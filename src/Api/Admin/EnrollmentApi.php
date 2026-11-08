<?php

declare(strict_types=1);

namespace Shepherd\Api\Admin;

use Shepherd\Audit;
use Shepherd\Auth\AdminAuth;
use Shepherd\Db;
use Shepherd\Http\HttpError;
use Shepherd\Http\Request;
use Shepherd\Http\Response;
use Shepherd\Scope;
use Shepherd\Util;

final class EnrollmentApi
{
    private const SELECT = 'SELECT t.*, p.name AS profile_name, a.username AS created_by_name
                              FROM enrollment_tokens t
                              JOIN profiles p ON p.id = t.profile_id
                              LEFT JOIN admins a ON a.id = t.created_by';

    private static function present(array $t): array
    {
        $expired = $t['expires_at'] !== null && strtotime($t['expires_at'] . ' UTC') < time();
        $usedUp = $t['max_uses'] !== null && (int) $t['uses'] >= (int) $t['max_uses'];
        $state = match (true) {
            (bool) $t['revoked'] => 'revoked',
            $expired => 'expired',
            $usedUp => 'used_up',
            default => 'active',
        };
        $server = Util::baseUrl();
        return [
            'id' => (int) $t['id'],
            'code' => Util::formatCode($t['code']),
            'label' => $t['label'],
            'profile_id' => (int) $t['profile_id'],
            'profile_name' => $t['profile_name'],
            'name_prefix' => $t['name_prefix'],
            'max_uses' => $t['max_uses'] === null ? null : (int) $t['max_uses'],
            'uses' => (int) $t['uses'],
            'expires_at' => Util::iso($t['expires_at']),
            'state' => $state,
            'server' => $server,
            // Scanned (QR) or opened as a link on the tablet; see docs/PROTOCOL.md
            'enroll_uri' => 'kiosky://enroll?server=' . rawurlencode($server) . '&token=' . $t['code'],
            'created_by' => $t['created_by_name'],
            'created_at' => Util::iso($t['created_at']),
        ];
    }

    /** GET /api/v1/admin/enrollment-tokens?include_inactive=1 */
    public static function list(Request $req): Response
    {
        $p = AdminAuth::require($req, 'operator');
        Scope::forbid($p, 'enrollment codes');
        $items = array_map(static fn ($r) => self::present($r), Db::all(self::SELECT . ' ORDER BY t.id DESC LIMIT 500'));
        if (($req->query['include_inactive'] ?? '') !== '1') {
            $items = array_values(array_filter($items, static fn ($t) => $t['state'] === 'active'));
        }
        return Response::json($items);
    }

    /** POST /api/v1/admin/enrollment-tokens  {profile_id, label?, name_prefix?, expires_hours?, max_uses?} */
    public static function create(Request $req): Response
    {
        $p = AdminAuth::require($req, 'operator');
        Scope::forbid($p, 'enrollment codes');
        $b = $req->json();
        $errors = [];

        $profileId = $b['profile_id'] ?? 1;
        if (!is_int($profileId) || Db::value('SELECT id FROM profiles WHERE id = :id', ['id' => $profileId]) === null) {
            $errors['profile_id'] = 'Choose an existing profile.';
        }
        $label = is_string($b['label'] ?? null) ? trim($b['label']) : '';
        if (mb_strlen($label) > 100) {
            $errors['label'] = 'Use at most 100 characters.';
        }
        $prefix = is_string($b['name_prefix'] ?? null) ? trim($b['name_prefix']) : '';
        if (mb_strlen($prefix) > 50) {
            $errors['name_prefix'] = 'Use at most 50 characters.';
        }
        $hours = $b['expires_hours'] ?? 72;
        if ($hours !== null && (!is_int($hours) || $hours < 1 || $hours > 24 * 365)) {
            $errors['expires_hours'] = 'Use 1 to 8760 hours, or null for no expiry.';
        }
        $maxUses = $b['max_uses'] ?? null;
        if ($maxUses !== null && (!is_int($maxUses) || $maxUses < 1 || $maxUses > 100000)) {
            $errors['max_uses'] = 'Use 1 to 100000, or null for unlimited.';
        }
        if ($errors !== []) {
            throw HttpError::validation($errors);
        }

        // 12 characters from a 32-letter alphabet: 60 bits, plus rate limiting on enroll
        $id = Db::insert('enrollment_tokens', [
            'code' => Util::code(12),
            'label' => $label,
            'profile_id' => $profileId,
            'name_prefix' => $prefix,
            'max_uses' => $maxUses,
            'expires_at' => $hours === null ? null : gmdate('Y-m-d H:i:s', time() + $hours * 3600),
            'created_by' => $p->adminId,
        ]);
        Audit::log($p, 'enrollment.created', 'enrollment_token', $id,
            ['label' => $label, 'profile_id' => $profileId, 'max_uses' => $maxUses, 'expires_hours' => $hours]);
        return Response::json(self::present(Db::one(self::SELECT . ' WHERE t.id = :id', ['id' => $id])), 201);
    }

    /** DELETE /api/v1/admin/enrollment-tokens/{id}  revokes the code */
    public static function revoke(Request $req): Response
    {
        $p = AdminAuth::require($req, 'operator');
        Scope::forbid($p, 'enrollment codes');
        $id = $req->intParam('id');
        if (Db::run('UPDATE enrollment_tokens SET revoked = 1 WHERE id = :id', ['id' => $id])->rowCount() === 0
            && Db::value('SELECT id FROM enrollment_tokens WHERE id = :id', ['id' => $id]) === null) {
            throw HttpError::notFound('Enrollment code');
        }
        Audit::log($p, 'enrollment.revoked', 'enrollment_token', $id);
        return Response::noContent();
    }
}
