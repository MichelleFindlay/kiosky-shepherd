// Kiosky Shepherd web console. Plain JavaScript on top of /api/v1/admin.
// All page content is built with DOM methods and textContent, never innerHTML.
'use strict';

/* ------------------------------------------------------------------ helpers */

function h(tag, attrs, ...children) {
  const el = document.createElement(tag);
  for (const [k, v] of Object.entries(attrs || {})) {
    if (v === null || v === undefined || v === false) continue;
    if (k === 'class') el.className = v;
    else if (k.startsWith('on') && typeof v === 'function') el.addEventListener(k.slice(2), v);
    else if (k === 'value') el.value = v;
    else if (k === 'checked' || k === 'disabled' || k === 'selected' || k === 'hidden') el[k] = !!v;
    else el.setAttribute(k, v === true ? '' : String(v));
  }
  append(el, children);
  return el;
}

function append(el, children) {
  for (const c of children.flat(Infinity)) {
    if (c === null || c === undefined || c === false) continue;
    el.append(c instanceof Node ? c : document.createTextNode(String(c)));
  }
  return el;
}

function clear(el) { while (el.firstChild) el.removeChild(el.firstChild); return el; }

function toast(message, bad = false) {
  const t = h('div', { class: 'toast' + (bad ? ' bad' : '') }, message);
  document.getElementById('toasts').append(t);
  setTimeout(() => t.remove(), bad ? 7000 : 3500);
}

function fail(err) {
  let msg = err.message || String(err);
  const fields = err.details && err.details.fields;
  if (fields) msg += ' ' + Object.entries(fields).map(([k, v]) => `${k}: ${v}`).join(' · ');
  toast(msg, true);
}

function ago(iso) {
  if (!iso) return 'never';
  const s = Math.round((Date.now() - Date.parse(iso)) / 1000);
  if (s < 0) return 'just now';
  if (s < 60) return `${s}s ago`;
  if (s < 3600) return `${Math.floor(s / 60)}m ago`;
  if (s < 86400) return `${Math.floor(s / 3600)}h ago`;
  return `${Math.floor(s / 86400)}d ago`;
}

function when(iso) {
  if (!iso) return '—';
  return h('span', { title: new Date(iso).toLocaleString() }, ago(iso));
}

function dateTime(iso) { return iso ? new Date(iso).toLocaleString() : '—'; }

function bytes(n) {
  if (n == null) return '—';
  const u = ['B', 'KB', 'MB', 'GB'];
  let i = 0;
  while (n >= 1024 && i < u.length - 1) { n /= 1024; i++; }
  return `${n.toFixed(i ? 1 : 0)} ${u[i]}`;
}

function fmtValue(v) {
  if (v === true) return 'On';
  if (v === false) return 'Off';
  if (v === null || v === undefined) return 'none';
  if (v === '') return '(empty)';
  if (Array.isArray(v)) return v.length ? v.join(', ') : '(any)';
  if (typeof v === 'object' && v.set) return 'set';
  if (typeof v === 'object') return JSON.stringify(v);
  return String(v);
}

function field(labelText, control, help) {
  return h('div', { class: 'field' }, h('label', {}, labelText, control), help ? h('div', { class: 'help' }, help) : null);
}

function onlineChip(d) {
  if (d.status === 'retired') return h('span', { class: 'chip' }, 'Retired');
  return d.online ? h('span', { class: 'chip ok' }, 'Online') : h('span', { class: 'chip bad' }, 'Offline');
}

function statusChip(s) {
  const cls = { done: 'ok', failed: 'bad', expired: 'warn', cancelled: '', pending: 'warn', delivered: '' }[s] || '';
  return h('span', { class: 'chip ' + cls }, s);
}

// True when this user only sees devices with certain tags
const scoped = () => !!(state.me && state.me.scope_tags && state.me.scope_tags.length);

const can = (role) => {
  const rank = { viewer: 1, operator: 2, admin: 3 };
  return state.me && rank[state.me.role] >= rank[role];
};

/* ---------------------------------------------------------------------- API */

const api = {
  csrf: null,
  async req(method, path, body, isForm) {
    const opts = { method, headers: { Accept: 'application/json' }, credentials: 'same-origin' };
    if (method !== 'GET' && this.csrf) opts.headers['X-CSRF-Token'] = this.csrf;
    if (body !== undefined) {
      if (isForm) opts.body = body;
      else { opts.headers['Content-Type'] = 'application/json'; opts.body = JSON.stringify(body); }
    }
    const res = await fetch('/api/v1' + path, opts);
    if (res.status === 204) return null;
    let data = null;
    try { data = await res.json(); } catch (e) { /* not JSON */ }
    if (!res.ok) {
      const e = data && data.error ? data.error : {};
      const err = new Error(e.message || `The server replied ${res.status}.`);
      err.status = res.status;
      err.code = e.code;
      err.details = e.details;
      if (res.status === 401 && e.code === 'unauthorized' && path !== '/admin/session') {
        state.me = null;
        renderLogin();
      }
      throw err;
    }
    return data;
  },
  get(p) { return this.req('GET', p); },
  post(p, b) { return this.req('POST', p, b === undefined ? {} : b); },
  patch(p, b) { return this.req('PATCH', p, b); },
  del(p) { return this.req('DELETE', p); },
  upload(p, form) { return this.req('POST', p, form, true); },
};

const state = { me: null, server: null, schema: null, commandTypes: null, timer: null };

async function schema() {
  if (!state.schema) state.schema = await api.get('/admin/policy/schema');
  return state.schema;
}

async function commandTypes() {
  if (!state.commandTypes) state.commandTypes = await api.get('/admin/commands/types');
  return state.commandTypes;
}

/* ------------------------------------------------------------------- layout */

const NAV = [
  ['#/overview', 'Overview', 'viewer'],
  ['#/devices', 'Devices', 'viewer'],
  ['#/profiles', 'Profiles', 'viewer'],
  ['#/enroll', 'Enrollment', 'operator', true],
  ['#/commands', 'Commands', 'viewer'],
  ['#/schedules', 'Schedules', 'viewer', true],
  ['#/releases', 'Releases', 'viewer'],
  ['#/users', 'Users', 'admin'],
  ['#/tokens', 'API tokens', 'viewer'],
  ['#/audit', 'Audit log', 'admin'],
  ['#/server', 'Server', 'admin'],
];

let mainEl = null;

function renderShell() {
  const app = clear(document.getElementById('app'));
  // The fourth column marks fleet-wide pages that users limited to some devices don't get
  const nav = h('nav', { class: 'nav' }, NAV.filter(([, , r, fleet]) => can(r) && !(fleet && scoped()))
    .map(([href, label]) => h('a', { href }, label)));
  mainEl = h('main', { id: 'main' });
  // On phones the sidebar collapses into a top bar; this button opens the menu
  const menuBtn = h('button', { class: 'menu-btn', type: 'button', 'aria-label': 'Menu', 'aria-expanded': 'false' },
    h('span', {}), h('span', {}), h('span', {}));
  const side = h('aside', { class: 'side' },
    h('div', { class: 'side-top' },
      h('a', { class: 'brand', href: '#/overview' }, h('img', { class: 'brand-mark', src: 'img/logo-128.png', alt: '' }), 'Shepherd'),
      menuBtn),
    nav,
    h('div', { class: 'side-foot' },
      h('div', {}, h('a', { href: '#/account' }, state.me.username), ' · ', state.me.role),
      h('div', { class: 'version', id: 'version-line' }, versionLine()),
      state.server && state.server.cron ? h('div', { class: 'version', id: 'cron-status' }, cronLine(state.server.cron)) : null,
      h('button', { class: 'small', onclick: logout }, 'Sign out')));
  menuBtn.addEventListener('click', () => setMenu(!side.classList.contains('open')));
  app.append(h('div', { class: 'shell' }, side, mainEl));
}

const GITHUB_URL = 'https://github.com/MichelleFindlay/kiosky-shepherd';

// "Kiosky Shepherd 1.4.0 · GitHub". The version comes with the session; if it's missing,
// it's fetched from the public health endpoint so the footer never goes blank.
function versionLine() {
  const v = state.server && state.server.version;
  if (!v) {
    fetch('/api/v1/health', { credentials: 'same-origin' }).then((r) => r.json()).then((hd) => {
      if (!hd || !hd.version) return;
      state.server = Object.assign(state.server || {}, { version: hd.version });
      const el = document.getElementById('version-line');
      if (el) clear(el).append(versionLine());
    }).catch(() => {});
  }
  return [`Kiosky Shepherd${v ? ' ' + v : ''}`, ' · ',
    h('a', { href: GITHUB_URL, target: '_blank', rel: 'noopener noreferrer' }, 'GitHub')];
}

// "Cron: 4m ago", with a warning when bin/cron.php hasn't run for 30 minutes
function cronLine(c) {
  return h('span', { title: c.last_run_at ? [dateTime(c.last_run_at), c.summary].filter(Boolean).join('\n') : 'bin/cron.php has never run' },
    'Cron: ', c.last_run_at ? ago(c.last_run_at) : 'never',
    c.stale ? [' ', h('span', { class: 'chip warn' }, c.last_run_at ? 'Overdue' : 'Not set up')] : null);
}

function setMenu(open) {
  const side = document.querySelector('.side');
  if (!side) return;
  side.classList.toggle('open', open);
  side.querySelector('.menu-btn').setAttribute('aria-expanded', String(open));
}

function highlightNav() {
  const hash = location.hash || '#/overview';
  document.querySelectorAll('.nav a').forEach((a) => {
    const href = a.getAttribute('href');
    a.classList.toggle('active', hash === href || hash.startsWith(href + '/') || hash.startsWith(href + '?'));
  });
}

function page(title, ...headExtras) {
  clear(mainEl);
  const head = h('div', { class: 'page-head' }, h('h1', {}, title), h('div', { class: 'spacer' }), ...headExtras);
  mainEl.append(migrationBanner(), updateBanner(), head);
  return mainEl;
}

// After an upgrade, admins can apply new database migrations here instead of over SSH
// Admins hear about a new Kiosky Shepherd release on every page (from cron's last check)
function updateBanner() {
  const u = state.server && state.server.update;
  if (!u || !u.available || !can('admin') || location.hash === '#/server') return null;
  return h('div', { class: 'card banner' },
    h('div', {}, h('strong', {}, `Kiosky Shepherd ${u.latest} is available. `), `You have ${state.server.version}.`),
    h('a', { class: 'btn small primary', href: '#/server' }, 'Review and install'));
}

async function viewServer() {
  page('Server');
  const box = h('div', { class: 'stack' });
  mainEl.append(box);
  const render = (st) => {
    clear(box);
    const c = st.check;
    const card = h('div', { class: 'card' }, h('h2', {}, 'Kiosky Shepherd updates'));
    card.append(h('dl', { class: 'kv' },
      h('dt', {}, 'Installed'), h('dd', {}, st.current),
      h('dt', {}, 'Newest release'), h('dd', {}, c && c.latest ? [c.latest, c.prerelease ? ' (pre-release)' : '',
        c.url ? [' · ', h('a', { href: c.url, target: '_blank', rel: 'noopener noreferrer' }, 'on GitHub')] : null] : 'not checked yet'),
      h('dt', {}, 'Last checked'), h('dd', {}, c && c.checked_at ? when(c.checked_at) : 'never',
        c && c.error ? h('div', { class: 'error-text' }, c.error) : null),
      h('dt', {}, 'Source'), h('dd', {}, h('code', {}, st.repo)),
      h('dt', {}, 'Automatic install'), h('dd', {}, st.auto ? 'On: cron installs new stable releases' : 'Off (self_update.auto)')));

    const checkBtn = h('button', { class: 'small' }, 'Check now');
    checkBtn.addEventListener('click', async () => {
      checkBtn.disabled = true;
      try { const r = await api.post('/admin/system/update/check'); syncBanner(r); render(r); } catch (ex) { fail(ex); checkBtn.disabled = false; }
    });
    const actions = h('div', { class: 'actions' }, checkBtn);
    card.append(actions);

    if (c && c.available) {
      if (c.notes) card.append(h('h3', {}, `What's new in ${c.latest}`), h('pre', { class: 'release-notes' }, c.notes));
      if (st.problems.length) {
        card.append(h('h3', {}, 'Can\'t install from here'), h('ul', {}, st.problems.map((p) => h('li', { class: 'error-text' }, p))),
          h('p', { class: 'sub' }, 'You can still update by uploading the release\'s files over the old ones (keep config/config.php and storage/).'));
      } else {
        const install = h('button', { class: 'primary' }, `Install ${c.latest}`);
        install.addEventListener('click', async () => {
          if (!window.confirm(`Install Kiosky Shepherd ${c.latest}? The current files are backed up first, and the console reloads when it's done.`)) return;
          install.disabled = true;
          install.textContent = 'Installing… (this can take a minute)';
          checkBtn.disabled = true;
          try {
            const r = await api.post('/admin/system/update/install', { version: c.latest });
            toast(`Installed ${r.to}. Reloading…`);
            setTimeout(() => location.reload(), 1500);
          } catch (ex) {
            fail(ex);
            install.disabled = false;
            install.textContent = `Install ${c.latest}`;
            checkBtn.disabled = false;
          }
        });
        actions.prepend(install);
        card.append(h('p', { class: 'sub' }, 'The current files are zipped into storage/backups first. config/config.php and storage/ are never changed. '
          + 'Database updates in the new version apply on the next page load.'));
      }
    } else if (c && c.latest) {
      card.append(h('p', {}, h('span', { class: 'chip ok' }, 'Up to date')));
    }
    box.append(card);

    if (st.last) {
      box.append(h('div', { class: 'card' }, h('h2', {}, 'Last change'),
        h('p', {}, st.last.action === 'installed' ? `Updated from ${st.last.from} to ${st.last.to}` : `Rolled back from ${st.last.from} to ${st.last.to}`,
          ` by ${st.last.by}, `, when(st.last.at), '.')));
    }

    const backups = h('div', { class: 'card' }, h('h2', {}, 'Backups'),
      h('p', { class: 'sub' }, 'Made before each update (the last five are kept). Rolling back restores the files only: '
        + 'database changes stay, and older versions ignore what they don\'t know.'));
    if (!st.backups.length) backups.append(h('div', { class: 'empty' }, 'No backups yet.'));
    else {
      backups.append(h('div', { class: 'table-wrap' }, h('table', {},
        h('thead', {}, h('tr', {}, h('th', {}, 'Version'), h('th', {}, 'Made'), h('th', { class: 'hide-sm' }, 'Size'), h('th', {}))),
        h('tbody', {}, st.backups.map((b) => h('tr', {},
          h('td', {}, b.version), h('td', {}, when(b.created_at)), h('td', { class: 'hide-sm' }, bytes(b.size)),
          h('td', {}, h('button', {
            class: 'small danger',
            onclick: async () => {
              if (!window.confirm(`Put back the Kiosky Shepherd ${b.version} files? The current files are backed up first.`)) return;
              try {
                const r = await api.post('/admin/system/update/rollback', { backup: b.name });
                toast(`Rolled back to ${r.to}. Reloading…`);
                setTimeout(() => location.reload(), 1500);
              } catch (ex) { fail(ex); }
            },
          }, `Roll back to ${b.version}`))))))));
    }
    box.append(backups);
  };
  const syncBanner = (st) => {
    if (state.server && st.check) state.server.update = { latest: st.check.latest, available: st.check.available };
  };
  render(await api.get('/admin/system/update'));
  mainEl.append(serverSettingsCard());
}

// Server settings saved in storage/settings.json on top of config/config.php
function serverSettingsCard() {
  const card = h('div', { class: 'card stack-gap' }, h('h2', {}, 'Settings'));
  const body = h('div', {});
  card.append(body);
  const show = (d) => {
    clear(body);
    body.append(h('p', { class: 'sub' }, 'Changes here are saved in storage/settings.json and take effect straight away, on top of config/config.php, '
      + 'which is never rewritten. Reset goes back to the config.php value.'));
    if (!d.writable) body.append(h('p', { class: 'error-text' }, 'PHP can\'t write to the storage folder, so settings can\'t be saved here.'));
    const rows = [];
    const groups = new Map();
    for (const s of d.settings) {
      if (!groups.has(s.group)) groups.set(s.group, h('div', { class: 'policy-group' }, h('h3', {}, s.group)));
      const ctl = settingControl(s, d.webhook_types);
      if (s.disabled_reason) ctl.disable();
      const reset = s.overridden ? h('button', {
        type: 'button', class: 'small',
        onclick: () => save({ [s.key]: null }),
      }, 'Reset') : null;
      rows.push({ s, ctl });
      groups.get(s.group).append(h('div', { class: 'policy-row' },
        h('div', { class: 'meta' }, h('label', {}, s.label), s.help ? h('div', { class: 'help' }, s.help) : null,
          h('div', { class: 'help mono' }, s.key)),
        h('div', { class: 'control' }, ctl.el,
          s.disabled_reason ? h('div', { class: 'inherit' }, s.disabled_reason)
            : s.overridden ? h('div', { class: 'inherit' }, 'Set here. config.php: ', h('code', {}, settingText(s, s.file_value)), ' ', reset)
              : h('div', { class: 'inherit' }, 'From config.php')))); 
    }
    const saveBtn = h('button', { class: 'primary', disabled: !d.writable }, 'Save settings');
    saveBtn.addEventListener('click', () => {
      const changes = {};
      try {
        for (const { s, ctl } of rows) {
          if (s.disabled_reason) continue;
          const v = ctl.get();
          if (JSON.stringify(v) !== JSON.stringify(normaliseForCompare(s, s.value))) changes[s.key] = v;
        }
      } catch (ex) { fail(ex); return; }
      if (!Object.keys(changes).length) { toast('Nothing changed.'); return; }
      save(changes);
    });
    body.append(...groups.values(), h('div', { class: 'actions' }, saveBtn),
      h('details', {}, h('summary', { class: 'sub' }, 'Only in config/config.php'),
        h('p', { class: 'sub' }, 'For safety these can\'t be changed in the console: ', d.file_only.join(', '), '.')));
  };
  const save = async (changes) => {
    try {
      const r = await api.patch('/admin/system/settings', changes);
      toast(r.changed.length ? `Saved ${r.changed.length} setting${r.changed.length === 1 ? '' : 's'}.` : 'Nothing changed.');
      show(r);
    } catch (ex) { fail(ex); }
  };
  api.get('/admin/system/settings').then(show).catch((ex) => body.append(h('div', { class: 'error-text' }, ex.message)));
  return card;
}

function settingText(s, v) {
  if (s.type === 'webhooks') return v.length ? v.map((w) => w.type).join(', ') : 'none';
  return fmtValue(v);
}

function normaliseForCompare(s, v) {
  if (s.type === 'webhooks') return (v || []).map((w) => ({ type: w.type, url: w.url }));
  if (s.type === 'emails' || s.type === 'tags') return v || [];
  // An unset text setting reads back as an empty field; don't count that as a change
  if (v == null && ['string', 'url', 'email', 'timezone'].includes(s.type)) return '';
  return v;
}

function settingControl(s, webhookTypes) {
  let el;
  let get;
  const inputs = [];
  switch (s.type) {
    case 'bool':
      el = h('input', { type: 'checkbox', checked: !!s.value });
      inputs.push(el);
      get = () => el.checked;
      return { el: h('label', { class: 'check' }, el, 'On'), get, disable: () => { el.disabled = true; } };
    case 'int':
      el = h('input', { type: 'number', min: s.min, max: s.max, value: s.value ?? '' });
      get = () => {
        const n = parseInt(el.value, 10);
        if (Number.isNaN(n)) throw new Error(`${s.label}: enter a number.`);
        return n;
      };
      break;
    case 'emails':
      el = h('textarea', { placeholder: 'it@example.com' });
      el.value = (s.value || []).join('\n');
      get = () => el.value.split(/[\s,;]+/).map((x) => x.trim()).filter(Boolean);
      break;
    case 'tags':
      el = h('input', { type: 'text', value: (s.value || []).join(', ') });
      get = () => parseTags(el.value);
      break;
    case 'timezone': {
      el = h('input', { type: 'text', value: s.value || '', list: 'settings-tz' });
      let list = null;
      try { list = h('datalist', { id: 'settings-tz' }, Intl.supportedValuesOf('timeZone').map((z) => h('option', { value: z }))); } catch (e) { /* free text */ }
      get = () => el.value.trim();
      return { el: h('div', {}, el, list), get, disable: () => { el.disabled = true; } };
    }
    case 'webhooks': {
      const list = h('div', { class: 'webhooks' });
      const hooks = [];
      const addRow = (w) => {
        const type = h('select', {}, webhookTypes.map((t) => h('option', { value: t, selected: t === w.type }, t)));
        const url = h('input', { type: 'url', value: w.url || '', placeholder: 'https://…' });
        const row = h('div', { class: 'webhook-row' }, type, url,
          w.has_headers ? h('span', { class: 'chip', title: 'Extra headers from config.php are kept while the address is unchanged' }, 'headers') : null);
        const entry = { type, url, row };
        row.append(h('button', { type: 'button', class: 'small', onclick: () => { hooks.splice(hooks.indexOf(entry), 1); row.remove(); } }, 'Remove'));
        hooks.push(entry);
        list.append(row);
      };
      (s.value || []).forEach(addRow);
      const add = h('button', { type: 'button', class: 'small', onclick: () => addRow({ type: 'slack', url: '' }) }, 'Add webhook');
      get = () => hooks.filter((x) => x.url.value.trim()).map((x) => ({ type: x.type.value, url: x.url.value.trim() }));
      return { el: h('div', {}, list, add), get, disable: () => { add.disabled = true; } };
    }
    default:
      el = h('input', { type: s.type === 'url' ? 'url' : s.type === 'email' ? 'email' : 'text', value: s.value ?? '' });
      get = () => el.value.trim();
  }
  return { el, get, disable: () => { el.disabled = true; } };
}

function migrationBanner() {
  const pending = state.server && state.server.pending_migrations;
  if (!pending || !pending.length) return null;
  const btn = h('button', { class: 'primary small' }, 'Update database');
  btn.addEventListener('click', async () => {
    btn.disabled = true;
    try {
      const r = await api.post('/admin/migrate');
      state.server.pending_migrations = [];
      toast(r.applied.length ? `Applied ${r.applied.join(', ')}.` : 'The database was already up to date.');
      route();
    } catch (ex) { fail(ex); btn.disabled = false; }
  });
  return h('div', { class: 'card banner' },
    h('div', {}, h('strong', {}, 'The database needs an update. '),
      `This version of Kiosky Shepherd added ${pending.join(', ')}. Back up the database first if you can.`),
    btn);
}

// Reasons the server's /sso/…/callback can send back as #sso-error=…
const SSO_ERRORS = {
  sso_unavailable: 'That sign-in option isn\'t set up on this server.',
  sso_expired: 'The sign-in took too long or was started in another browser tab or window. Try again.',
  sso_failed: 'Single sign-on didn\'t work. An admin can find the reason in the audit log or the server\'s error log.',
  sso_denied: 'The identity provider didn\'t sign you in.',
  no_role: 'Your account isn\'t in any group or app role that\'s allowed into Kiosky Shepherd. Ask an admin for access.',
  disabled: 'Your Kiosky Shepherd account is disabled.',
  groups_overage: 'You\'re in too many groups for them to fit in the sign-in token. Ask an admin to use app roles, or to send only the groups assigned to the app.',
};

async function renderLogin() {
  stopTimer();
  let setupError = null;
  let ssoError = null;
  const m = location.hash.match(/^#sso-error=([a-z_]+)$/);
  if (m) {
    ssoError = SSO_ERRORS[m[1]] || SSO_ERRORS.sso_failed;
    history.replaceState(null, '', location.pathname + location.search);
  }
  let options = { password: true, providers: [] };
  try {
    const [s, o] = await Promise.all([api.get('/admin/setup'), api.get('/admin/login-options')]);
    if (s.needed) { renderSetup(s.enabled, s.install); return; }
    options = o;
  } catch (e) {
    setupError = `The server API isn't answering properly (${e.message}). Open /api/v1/health to check it.`;
  }
  const user = h('input', { type: 'text', autocomplete: 'username', required: true });
  const pass = h('input', { type: 'password', autocomplete: 'current-password', required: true });
  const err = h('div', { class: 'error-text', hidden: true });
  const form = options.password ? h('form', {
    onsubmit: async (e) => {
      e.preventDefault();
      err.hidden = true;
      try {
        const r = await api.post('/admin/session', { username: user.value, password: pass.value });
        if (r.two_factor_required) renderSecondFactor();
        else signedIn(r);
      } catch (ex) {
        err.textContent = ex.message;
        err.hidden = false;
      }
    },
  },
  field('Username', user), field('Password', pass), err,
  h('button', { class: 'primary', type: 'submit' }, 'Sign in')) : null;
  const sso = options.providers.length ? h('div', { class: 'sso' },
    options.providers.map((p) => h('a', { class: 'btn', href: p.url }, p.label))) : null;
  clear(document.getElementById('app')).append(h('div', { class: 'login' },
    h('div', { class: 'card' }, logo(), h('h1', {}, 'Kiosky Shepherd'), h('p', { class: 'sub' }, 'Sign in to manage your kiosks.'),
      setupError ? h('p', { class: 'error-text' }, setupError) : null,
      ssoError ? h('p', { class: 'error-text' }, ssoError) : null,
      sso, sso && form ? h('div', { class: 'or' }, 'or') : null, form,
      !sso && !form && !setupError ? h('p', { class: 'error-text' }, 'No sign-in method is turned on in config/config.php.') : null)));
  if (form) user.focus();
}

// Second step of a password sign-in when the user has two-factor sign-in on
function renderSecondFactor() {
  const code = h('input', { type: 'text', inputmode: 'numeric', autocomplete: 'one-time-code', required: true, maxlength: 16, class: 'code-input' });
  const err = h('div', { class: 'error-text', hidden: true });
  const form = h('form', {
    onsubmit: async (e) => {
      e.preventDefault();
      err.hidden = true;
      try {
        signedIn(await api.post('/admin/session/2fa', { code: code.value }));
      } catch (ex) {
        if (ex.code === 'two_factor_expired') { renderLogin(); toast(ex.message, true); return; }
        err.textContent = ex.message;
        err.hidden = false;
        code.select();
      }
    },
  },
  field('Code', code, 'The 6-digit code from your authenticator app, or one of your recovery codes.'), err,
  h('button', { class: 'primary', type: 'submit' }, 'Verify'));
  clear(document.getElementById('app')).append(h('div', { class: 'login' },
    h('div', { class: 'card' }, logo(), h('h1', {}, 'Two-factor sign-in'), form,
      h('p', { class: 'sub' }, h('a', { href: '#', onclick: (e) => { e.preventDefault(); renderLogin(); } }, '← Use a different account')))));
  code.focus();
}

function logo() {
  return h('img', { class: 'login-logo', src: 'img/logo-256.png', alt: 'Kiosky Shepherd logo', width: 112, height: 112 });
}

function renderSetup(enabled, install) {
  const box = h('div', { class: 'card' }, logo(), h('h1', {}, 'Set up Kiosky Shepherd'));
  if (!enabled) {
    box.append(h('p', {}, 'There are no console users yet. Either run ', h('code', {}, 'php bin/create-admin.php <name>'),
      ' on the server, or put a long random ', h('code', {}, 'setup_key'), ' in config/config.php and reload this page.'));
  } else {
    const key = h('input', { type: 'password', autocomplete: 'off', required: true });
    const user = h('input', { type: 'text', autocomplete: 'username', required: true, value: 'admin' });
    const pass = h('input', { type: 'password', autocomplete: 'new-password', required: true, minlength: 10 });
    const err = h('div', { class: 'error-text', hidden: true });
    box.append(h('p', { class: 'sub' }, install
      ? 'This creates the database tables and the first admin account. Afterwards, remove setup_key from config/config.php.'
      : 'Create the first admin account. Afterwards, remove setup_key from config/config.php.'),
      h('form', {
        onsubmit: async (e) => {
          e.preventDefault();
          err.hidden = true;
          try {
            signedIn(await api.post('/admin/setup', { setup_key: key.value, username: user.value, password: pass.value }));
          } catch (ex) {
            const f = ex.details && ex.details.fields;
            err.textContent = f ? Object.values(f).join(' ') : ex.message;
            err.hidden = false;
          }
        },
      }, field('Setup key', key, 'The setup_key value from config/config.php.'), field('Username', user),
      field('Password', pass, 'At least 10 characters.'), err, h('button', { class: 'primary', type: 'submit' }, install ? 'Install' : 'Create admin')));
  }
  clear(document.getElementById('app')).append(h('div', { class: 'login' }, box));
}

async function logout() {
  try { await api.del('/admin/session'); } catch (e) { /* already gone */ }
  state.me = null;
  api.csrf = null;
  renderLogin();
}

function stopTimer() {
  if (state.timer) { clearInterval(state.timer); state.timer = null; }
  if (state.liveTimer) { clearInterval(state.liveTimer); state.liveTimer = null; }
}

function every(ms, fn) {
  stopTimer();
  state.timer = setInterval(() => { if (!document.hidden) fn(); }, ms);
}

/* ------------------------------------------------------------------- router */

const ROUTES = [
  [/^#\/overview$/, viewOverview],
  [/^#\/devices(?:\?(.*))?$/, viewDevices],
  [/^#\/devices\/(\d+)$/, viewDevice],
  [/^#\/profiles$/, viewProfiles],
  [/^#\/profiles\/(\d+)$/, viewProfile],
  [/^#\/enroll$/, viewEnroll],
  [/^#\/commands$/, viewCommands],
  [/^#\/schedules$/, viewSchedules],
  [/^#\/releases$/, viewReleases],
  [/^#\/users$/, viewUsers],
  [/^#\/tokens$/, viewTokens],
  [/^#\/audit$/, viewAudit],
  [/^#\/account$/, viewAccount],
  [/^#\/server$/, viewServer],
];

async function route() {
  if (!state.me) return;
  stopTimer();
  const hash = location.hash || '#/overview';
  setMenu(false);
  highlightNav();
  for (const [re, view] of ROUTES) {
    const m = hash.match(re);
    if (m) {
      try {
        await view(...m.slice(1));
      } catch (e) {
        if (e.status !== 401) {
          page('Something went wrong');
          mainEl.append(h('div', { class: 'card' }, e.message));
        }
      }
      return;
    }
  }
  location.hash = '#/overview';
}

/* ----------------------------------------------------------------- overview */

async function viewOverview() {
  const render = async () => {
    const o = await api.get('/admin/overview');
    page('Overview', can('operator') && !scoped() ? h('a', { class: 'btn', href: '#/enroll' }, 'Enroll devices') : null);
    if (scoped()) mainEl.append(h('p', { class: 'sub' }, `You see devices tagged ${state.me.scope_tags.join(', ')}.`));
    const tile = (num, label, href) => h('a', { class: 'card tile', href }, h('div', { class: 'num' }, num), h('div', { class: 'label' }, label));
    mainEl.append(h('div', { class: 'tiles' },
      tile(o.devices.total, 'Managed devices', '#/devices'),
      tile(o.devices.online, 'Online now', '#/devices'),
      tile(o.devices.policy_pending, 'Waiting to apply policy', '#/devices'),
      tile(o.pending_commands, 'Commands in flight', '#/commands')));

    const attention = h('div', { class: 'card' }, h('h2', {}, 'Needs attention'));
    if (!o.attention.length) attention.append(h('div', { class: 'empty' }, 'All devices look healthy.'));
    else {
      attention.append(h('div', { class: 'table-wrap' }, h('table', {},
        h('thead', {}, h('tr', {}, h('th', {}, 'Device'), h('th', {}, 'Why'), h('th', {}, 'Last seen'))),
        h('tbody', {}, o.attention.map((a) => h('tr', { class: 'clickable', onclick: () => { location.hash = `#/devices/${a.id}`; } },
          h('td', {}, a.name), h('td', {}, a.reasons.join(', ')), h('td', {}, when(a.last_seen_at))))))));
    }

    const versions = h('div', { class: 'card' }, h('h2', {}, 'Kiosky versions'));
    const vEntries = Object.entries(o.app_versions);
    if (!vEntries.length) versions.append(h('div', { class: 'empty' }, 'No devices yet.'));
    else versions.append(h('dl', { class: 'kv' }, vEntries.map(([v, n]) => [h('dt', {}, v), h('dd', {}, `${n} device${n === 1 ? '' : 's'}`)])));

    const events = h('div', { class: 'card' }, h('h2', {}, 'Recent device events'));
    if (!o.recent_events.length) events.append(h('div', { class: 'empty' }, 'Nothing yet.'));
    else {
      events.append(h('div', { class: 'table-wrap' }, h('table', {}, h('tbody', {}, o.recent_events.map((e) => h('tr', {},
        h('td', {}, h('a', { href: `#/devices/${e.device_id}` }, e.device_name)),
        h('td', {}, h('code', {}, e.type)),
        h('td', { class: 'trunc' }, e.data ? JSON.stringify(e.data.data ?? e.data) : ''),
        h('td', {}, when(e.created_at))))))));
    }
    const c = o.cron;
    if (state.server) state.server.cron = c;
    refreshCronLine();
    const server = h('div', { class: 'card' }, h('h2', {}, 'Server'), h('dl', { class: 'kv' },
      h('dt', {}, 'Version'), h('dd', {}, state.server ? state.server.version : '—'),
      h('dt', {}, 'Last cron run'), h('dd', {},
        c.last_run_at ? h('span', { title: [dateTime(c.last_run_at), c.summary].filter(Boolean).join('\n') }, ago(c.last_run_at)) : 'never',
        c.stale ? [' ', h('span', { class: 'chip warn' }, c.last_run_at ? 'Overdue' : 'Not set up')] : null)),
      c.stale ? h('p', { class: 'sub' }, 'Schedule ', h('code', {}, 'php bin/cron.php'),
        ' every 10 minutes (cPanel → Cron Jobs). It runs scheduled commands, sends offline alerts, expires old commands and prunes old events.') : null,
      alertsSummary(o.alerts));
    server.querySelector('dl').append(h('dt', {}, 'Offline alerts'), h('dd', {}, alertsLine(o.alerts)));
    mainEl.append(h('div', { class: 'grid grid-2' }, attention, h('div', { class: 'stack' }, versions, server, events)));
  };
  await render();
  every(30000, () => render().catch(() => {}));
}

function alertsLine(a) {
  if (!a) return '—';
  const channels = [];
  if (a.email) channels.push(`${a.email} email address${a.email === 1 ? '' : 'es'}`);
  for (const w of a.webhooks) channels.push(w);
  return channels.length ? `${channels.join(', ')} · after ${a.offline_minutes} min` : 'Off';
}

function alertsSummary(a) {
  if (!a || !can('admin')) return null;
  if (!a.email && !a.webhooks.length) {
    return h('p', { class: 'sub' }, 'To be told when kiosks go offline, add email addresses or webhooks under ',
      h('code', {}, "'alerts'"), ' in config/config.php.');
  }
  const btn = h('button', { class: 'small' }, 'Send test alert');
  btn.addEventListener('click', async () => {
    btn.disabled = true;
    try {
      const r = await api.post('/admin/alerts/test');
      const bad = r.results.filter((x) => !x.ok);
      if (!bad.length) toast(`Sent to ${r.results.map((x) => x.channel).join(', ')}.`);
      else toast(bad.map((x) => `${x.channel}: ${x.error}`).join(' · '), true);
    } catch (ex) { fail(ex); } finally { btn.disabled = false; }
  });
  return h('div', { class: 'actions' }, btn,
    a.ignore_tags.length ? h('span', { class: 'sub' }, `Devices tagged ${a.ignore_tags.join(', ')} are never reported.`) : null);
}

/* ------------------------------------------------------------------ devices */

// opts.label: the submit button's text. opts.extra: fields shown after the parameters.
// opts.initial: {type, params} to start from.
function commandForm(types, onSend, opts = {}) {
  const initial = opts.initial || {};
  const select = h('select', {}, types.map((t) => h('option', { value: t.type, selected: t.type === initial.type }, t.label)));
  const paramsBox = h('div', {});
  let inputs = {};
  const renderParams = () => {
    clear(paramsBox);
    inputs = {};
    const t = types.find((x) => x.type === select.value);
    for (const [name, p] of Object.entries(t.params)) {
      let input;
      const start = initial.type === t.type && initial.params ? initial.params[name] : undefined;
      if (p.type === 'profile') {
        input = h('select', {}, (opts.profiles || []).map((x) => h('option', { value: x.id, selected: x.id === start }, x.name)));
      } else if (p.type === 'int') input = h('input', { type: 'number', min: p.min, max: p.max, value: start ?? p.default ?? '' });
      else if (p.type === 'string') { input = h('textarea', { maxlength: p.max }); input.value = start ?? ''; }
      else input = h('input', { type: 'url', placeholder: 'https://', value: start ?? '' });
      inputs[name] = { input, p };
      paramsBox.append(field(name.replace(/_/g, ' ') + (p.required ? '' : ' (optional)'), input));
    }
  };
  select.addEventListener('change', renderParams);
  renderParams();
  const button = h('button', { class: 'primary', type: 'submit' }, opts.label || 'Send');
  return h('form', {
    onsubmit: async (e) => {
      e.preventDefault();
      const params = {};
      for (const [name, { input, p }] of Object.entries(inputs)) {
        if (input.value === '') continue;
        params[name] = p.type === 'int' || p.type === 'profile' ? parseInt(input.value, 10) : input.value;
      }
      button.disabled = true;
      try { await onSend(select.value, params); } catch (ex) { fail(ex); } finally { button.disabled = false; }
    },
  }, field('Command', select), paramsBox, opts.extra || null, button);
}

// Tag chips; clicking one filters the device list by it
function tagChips(tags) {
  if (!tags || !tags.length) return null;
  return h('span', { class: 'tags' }, tags.map((t) => h('a', {
    class: 'chip tag', href: `#/devices?tag=${encodeURIComponent(t)}`, onclick: (e) => e.stopPropagation(),
  }, t)));
}

function parseTags(text) {
  return text.split(/[\s,]+/).map((s) => s.trim().toLowerCase()).filter(Boolean);
}

async function viewDevices(query) {
  const [profiles, tags] = await Promise.all([api.get('/admin/profiles'), api.get('/admin/tags')]);
  const initialTag = new URLSearchParams(query || '').get('tag') || '';
  const tagSel = h('select', {}, h('option', { value: '' }, 'All tags'),
    tags.map((t) => h('option', { value: t.tag, selected: t.tag === initialTag }, `${t.tag} (${t.devices})`)),
    initialTag && !tags.some((t) => t.tag === initialTag) ? h('option', { value: initialTag, selected: true }, initialTag) : null);
  const q = h('input', { type: 'search', placeholder: 'Search name, model, IP, notes…' });
  const profileSel = h('select', {}, h('option', { value: '' }, 'All profiles'), profiles.map((p) => h('option', { value: p.id }, p.name)));
  const statusSel = h('select', {}, h('option', { value: 'active' }, 'Active'), h('option', { value: 'retired' }, 'Retired'), h('option', { value: 'all' }, 'All'));
  page('Devices', can('operator') && !scoped() ? h('a', { class: 'btn', href: '#/enroll' }, 'Enroll devices') : null);

  const selected = new Set();
  const tableBox = h('div', {});
  const bulkBox = h('div', { class: 'card', hidden: true });
  const count = h('strong', {});

  if (can('operator')) {
    const types = await commandTypes();
    bulkBox.append(h('h2', {}, 'Send a command to ', count),
      commandForm(types, async (type, params) => {
        const r = await api.post('/admin/commands', { type, params, device_ids: [...selected] });
        toast(`Queued for ${r.queued} device${r.queued === 1 ? '' : 's'}.`);
      }));
    const tagInput = h('input', { type: 'text', placeholder: 'e.g. lobby, floor-2' });
    const tagAction = async (op) => {
      const list = parseTags(tagInput.value);
      if (!list.length) { toast('Type one or more tags first.', true); return; }
      try {
        const r = await api.post('/admin/device-tags', { device_ids: [...selected], [op]: list });
        toast(`${op === 'add' ? 'Tagged' : 'Untagged'} ${r.updated} device${r.updated === 1 ? '' : 's'}.`
          + (r.skipped_too_many_tags.length ? ` ${r.skipped_too_many_tags.length} skipped: too many tags.` : ''));
        tagInput.value = '';
        load().catch(fail);
      } catch (ex) { fail(ex); }
    };
    bulkBox.append(h('h3', {}, 'Tags'), h('div', { class: 'row' }, field('Tags', tagInput),
      h('div', { class: 'field' }, h('div', { class: 'actions' },
        h('button', { type: 'button', onclick: () => tagAction('add') }, 'Add to selected'),
        h('button', { type: 'button', onclick: () => tagAction('remove') }, 'Remove from selected')))));
  }

  const load = async () => {
    const params = new URLSearchParams({ status: statusSel.value });
    if (q.value.trim()) params.set('q', q.value.trim());
    if (profileSel.value) params.set('profile_id', profileSel.value);
    if (tagSel.value) params.set('tag', tagSel.value);
    const r = await api.get('/admin/devices?' + params);
    clear(tableBox);
    if (!r.items.length) {
      tableBox.append(h('div', { class: 'empty' }, statusSel.value === 'active' && !q.value && !profileSel.value && !tagSel.value
        ? 'No devices yet. Create an enrollment code to add some.' : 'No devices match.'));
      return;
    }
    const syncBulk = () => {
      count.textContent = `${selected.size} device${selected.size === 1 ? '' : 's'}`;
      bulkBox.hidden = selected.size === 0;
    };
    const all = h('input', { type: 'checkbox', 'aria-label': 'Select all' });
    const boxes = [];
    all.addEventListener('change', () => {
      boxes.forEach(([b, id]) => { b.checked = all.checked; if (all.checked) selected.add(id); else selected.delete(id); });
      syncBulk();
    });
    tableBox.append(h('div', { class: 'table-wrap' }, h('table', {},
      h('thead', {}, h('tr', {}, can('operator') ? h('th', {}, all) : null, h('th', {}, 'Name'), h('th', { class: 'hide-sm' }, 'Profile'),
        h('th', { class: 'hide-sm' }, 'Model'), h('th', {}, 'Kiosky'), h('th', { class: 'hide-sm' }, 'Page'), h('th', { class: 'hide-sm' }, 'Policy'),
        h('th', { class: 'num hide-sm', title: 'Share of the last 7 days the device was online' }, 'Online 7d'), h('th', {}, 'Last seen'))),
      h('tbody', {}, r.items.map((d) => {
        const go = () => { location.hash = `#/devices/${d.id}`; };
        let box = null;
        if (can('operator') && d.status === 'active') {
          box = h('input', { type: 'checkbox', checked: selected.has(d.id), onclick: (e) => e.stopPropagation() });
          box.addEventListener('change', () => { if (box.checked) selected.add(d.id); else selected.delete(d.id); syncBulk(); });
          boxes.push([box, d.id]);
        }
        const st = d.state || {};
        return h('tr', { class: 'clickable', onclick: go },
          can('operator') ? h('td', {}, box) : null,
          h('td', {}, h('span', { class: 'dot ' + (d.online ? 'ok' : (d.status === 'active' ? 'bad' : '')) }), d.name, tagChips(d.tags)),
          h('td', { class: 'hide-sm' }, d.profile_name),
          h('td', { class: 'hide-sm' }, [d.manufacturer, d.model].filter(Boolean).join(' ') || '—'),
          h('td', {}, d.app_version || '—'),
          h('td', { class: 'trunc hide-sm', title: st.current_url || '' }, st.current_url || '—'),
          h('td', { class: 'hide-sm' }, d.status !== 'active' ? '—' : d.policy_current ? h('span', { class: 'chip ok' }, 'Applied') : h('span', { class: 'chip warn' }, 'Pending')),
          h('td', { class: 'num hide-sm' }, uptimeCell(d.uptime_7d)),
          h('td', {}, when(d.last_seen_at)));
      })))));
    syncBulk();
  };

  let debounce = null;
  q.addEventListener('input', () => { clearTimeout(debounce); debounce = setTimeout(() => load().catch(fail), 250); });
  profileSel.addEventListener('change', () => load().catch(fail));
  tagSel.addEventListener('change', () => {
    // Keep the tag in the address, so tag links and the back button work
    history.replaceState(null, '', tagSel.value ? `#/devices?tag=${encodeURIComponent(tagSel.value)}` : '#/devices');
    load().catch(fail);
  });
  statusSel.addEventListener('change', () => { selected.clear(); load().catch(fail); });

  mainEl.append(h('div', { class: 'stack' },
    h('div', { class: 'row' }, h('div', { class: 'field' }, q), h('div', { class: 'field' }, profileSel),
      h('div', { class: 'field' }, tagSel), h('div', { class: 'field' }, statusSel)),
    h('div', { class: 'card' }, tableBox),
    bulkBox));
  await load();
  every(30000, () => load().catch(() => {}));
}

/* Policy editor shared by profiles (inherit from defaults) and devices (inherit from profile) */
function policyEditor({ defs, stored, inherited, releases, editable, setLabel }) {
  const rows = [];
  const groups = new Map();
  for (const def of defs) {
    if (!groups.has(def.group)) groups.set(def.group, h('div', { class: 'policy-group' }, h('h3', {}, def.group)));
    const isSet = Object.prototype.hasOwnProperty.call(stored, def.key);
    const inh = inherited[def.key];
    const ctl = makeControl(def, isSet ? stored[def.key] : inh.value, releases, isSet);
    const chk = h('input', { type: 'checkbox', checked: isSet, disabled: !editable });
    const note = h('div', { class: 'inherit' }, `Inherits ${fmtValue(inh.value)} from ${inh.from}`);
    const sync = () => { ctl.enable(chk.checked && editable); note.hidden = chk.checked; };
    chk.addEventListener('change', sync);
    sync();
    rows.push({ def, chk, ctl, wasSet: isSet });
    groups.get(def.group).append(h('div', { class: 'policy-row' },
      h('div', { class: 'meta' }, h('label', {}, def.label), def.help ? h('div', { class: 'help' }, def.help) : null,
        h('div', { class: 'help mono' }, def.key)),
      h('div', { class: 'control' }, h('label', { class: 'check' }, chk, setLabel), ctl.el, note)));
  }
  return {
    el: h('div', {}, [...groups.values()]),
    changes() {
      const out = {};
      for (const r of rows) {
        if (!r.chk.checked) {
          if (r.wasSet) out[r.def.key] = null;
          continue;
        }
        const v = r.ctl.get();
        if (v !== undefined) out[r.def.key] = v;
      }
      return out;
    },
  };
}

function makeControl(def, value, releases, wasSet) {
  let el;
  let get;
  switch (def.type) {
    case 'bool':
      el = h('input', { type: 'checkbox', checked: !!value });
      get = () => el.checked;
      return { el: h('label', { class: 'check' }, el, 'On'), get, enable: (b) => { el.disabled = !b; } };
    case 'int':
      el = h('input', { type: 'number', min: def.min, max: def.max, value: value ?? '' });
      get = () => {
        const n = parseInt(el.value, 10);
        if (Number.isNaN(n)) throw new Error(`${def.label}: enter a number.`);
        return n;
      };
      break;
    case 'enum':
      el = h('select', {}, def.values.map((v) => h('option', { value: v, selected: v === value }, v)));
      get = () => el.value;
      break;
    case 'hosts':
      el = h('textarea', { placeholder: 'one host per line' });
      el.value = Array.isArray(value) ? value.join('\n') : '';
      get = () => el.value.split(/[\s,]+/).map((s) => s.trim()).filter(Boolean);
      break;
    case 'release': {
      const opts = ['latest', ...releases.map((r) => r.version_name)];
      if (value && !opts.includes(value)) opts.push(value);
      el = h('select', {}, opts.map((v) => h('option', { value: v, selected: v === value }, v)));
      get = () => el.value;
      break;
    }
    case 'time':
      el = h('input', { type: 'time', value: value || '' });
      get = () => el.value;
      break;
    case 'pin':
      el = h('input', { type: 'password', inputmode: 'numeric', autocomplete: 'new-password',
        placeholder: wasSet ? 'PIN is set; type to change' : '4–12 digits' });
      get = () => {
        if (el.value === '') {
          if (wasSet) return undefined; // keep the stored PIN
          throw new Error(`${def.label}: enter a PIN or untick it.`);
        }
        return el.value;
      };
      break;
    default:
      el = h('input', { type: def.type === 'url' ? 'url' : 'text', value: value ?? '', placeholder: def.type === 'url' ? 'https://' : '' });
      get = () => el.value;
  }
  return { el, get, enable: (b) => { el.disabled = !b; } };
}

async function viewDevice(id) {
  const [d, pol, defs, profiles, releases] = await Promise.all([
    api.get(`/admin/devices/${id}`), api.get(`/admin/devices/${id}/policy`), schema(),
    api.get('/admin/profiles'), api.get('/admin/releases'),
  ]);
  const editable = can('operator') && d.status === 'active';
  page(d.name, onlineChip(d), h('span', { class: 'sub mono' }, d.uid));
  mainEl.querySelector('.page-head').prepend(h('a', { href: '#/devices', class: 'sub' }, '← Devices'));

  // Device settings
  const name = h('input', { type: 'text', value: d.name, disabled: !editable });
  const prof = h('select', { disabled: !editable }, profiles.map((p) => h('option', { value: p.id, selected: p.id === d.profile_id }, p.name)));
  const notes = h('textarea', { disabled: !editable });
  notes.value = d.notes || '';
  const tagsInput = h('input', { type: 'text', value: (d.tags || []).join(', '), disabled: !editable, placeholder: 'e.g. lobby, floor-2' });
  const infoCard = h('div', { class: 'card' }, h('h2', {}, 'Device'),
    h('form', {
      onsubmit: async (e) => {
        e.preventDefault();
        try {
          await api.patch(`/admin/devices/${id}`, {
            name: name.value, profile_id: parseInt(prof.value, 10), notes: notes.value, tags: parseTags(tagsInput.value),
          });
          toast('Saved.');
          route();
        } catch (ex) { fail(ex); }
      },
    }, field('Name', name), field('Profile', prof, 'The device gets this profile\'s policy, plus any overrides below.'),
    field('Tags', tagsInput, 'Labels for finding and targeting devices across profiles: lower-case letters, digits, - _ and ., separated by commas. Devices tagged no-alerts are left out of offline alerts.'),
    field('Notes', notes),
    editable ? h('button', { class: 'primary', type: 'submit' }, 'Save') : null));

  // Status
  const st = d.state || {};
  const statusRows = [
    ['Last seen', d.last_seen_at ? `${ago(d.last_seen_at)} (${dateTime(d.last_seen_at)})` : 'never'],
    ['IP address', d.last_ip || '—'],
    ['Hardware', [d.manufacturer, d.model].filter(Boolean).join(' ') || '—'],
    ['Android', d.android_version ? `${d.android_version} (SDK ${d.sdk_int ?? '?'})` : '—'],
    ['Kiosky', d.app_version ? `${d.app_version} (${d.app_version_code ?? '?'})` : '—'],
    ['Policy', d.status !== 'active' ? '—' : d.policy_current ? `applied (${d.policy_version})` : `pending: device has ${d.applied_policy_version || 'none'}, current is ${d.policy_version}`],
    ['Enrolled', dateTime(d.enrolled_at)],
  ];
  for (const [k, v] of Object.entries(st)) statusRows.push([k.replace(/_/g, ' '), fmtValue(v)]);
  const statusCard = h('div', { class: 'card' }, h('h2', {}, 'Status'),
    h('dl', { class: 'kv' }, statusRows.map(([k, v]) => [h('dt', {}, k), h('dd', {}, v)])));

  // Commands
  const cmdList = h('div', {});
  const shotsBox = h('div', {});
  // While this page is open the device is asked to check in every few seconds
  const watch = () => (d.status === 'active' ? api.post(`/admin/devices/${id}/watch`).catch(() => null) : Promise.resolve(null));
  const loadCommands = async () => {
    const [r, shots] = await Promise.all([
      api.get(`/admin/commands?device_id=${id}&limit=25`),
      api.get(`/admin/commands?device_id=${id}&type=screenshot&limit=12`),
      watch(),
    ]);
    renderCommandTable(cmdList, r.items, false, loadCommands);
    renderScreenshots(shotsBox, shots.items);
  };
  const cmdCard = h('div', { class: 'card' }, h('h2', {}, 'Commands'));
  if (editable) {
    cmdCard.append(commandForm(await commandTypes(), async (type, params) => {
      await api.post(`/admin/devices/${id}/commands`, { type, params });
      toast('Queued. The device picks it up at its next check-in.');
      loadCommands();
    }), h('h3', {}, 'History'));
  }
  cmdCard.append(cmdList);
  if (d.status === 'active') {
    cmdCard.append(h('p', { class: 'sub' }, 'While this page is open, the device checks in every few seconds instead of every ',
      `${d.checkin_seconds} s, so commands arrive quickly.`));
  }

  const shotsCard = h('div', { class: 'card' }, h('div', { class: 'card-head' }, h('h2', {}, 'Screenshots'),
    editable ? h('button', {
      class: 'small',
      onclick: async () => {
        try {
          await api.post(`/admin/devices/${id}/commands`, { type: 'screenshot', params: {} });
          toast('Screenshot requested.');
          loadCommands();
        } catch (ex) { fail(ex); }
      },
    }, 'Take screenshot') : null), shotsBox);

  // Live view: the device streams frames while a live_view command runs (needs Kiosky support)
  const liveCard = liveViewCard(d, editable);

  // Health history
  const healthCard = h('div', { class: 'card' }, h('div', { class: 'card-head' }, h('h2', {}, 'Health, last 30 days'),
    d.uptime_7d != null ? h('span', { class: 'sub' }, `Online ${d.uptime_7d}% of the last 7 days`) : null));
  api.get(`/admin/devices/${id}/history?days=30`).then((r) => healthCard.append(healthCharts(r))).catch(() => {
    healthCard.append(h('div', { class: 'empty' }, 'No history yet.'));
  });

  // Policy overrides
  const inherited = {};
  for (const def of defs) {
    const src = pol.sources[def.key] || '';
    if (src.startsWith('tag:')) inherited[def.key] = { value: pol.values[def.key], from: `the ${src.slice(4)} release ring` };
    else inherited[def.key] = Object.prototype.hasOwnProperty.call(pol.profile, def.key)
      ? { value: pol.profile[def.key], from: `profile ${d.profile_name}` }
      : { value: def.default, from: 'default' };
  }
  const editor = policyEditor({ defs, stored: pol.overrides, inherited, releases, editable, setLabel: 'Override' });
  const policyCard = h('div', { class: 'card' },
    h('h2', {}, 'Policy overrides'),
    h('p', { class: 'sub' }, 'Settings ticked here apply to this device only and beat its profile. ',
      pol.update ? `Update target: Kiosky ${pol.update.version}.` : 'No server-hosted update is targeted.'),
    editor.el,
    editable ? h('div', { class: 'actions' },
      h('button', {
        class: 'primary',
        onclick: async () => {
          try {
            await api.patch(`/admin/devices/${id}`, { overrides: editor.changes() });
            toast('Overrides saved. The device applies them at its next check-in.');
            route();
          } catch (ex) { fail(ex); }
        },
      }, 'Save overrides'),
      h('button', {
        onclick: async () => {
          if (!window.confirm('Remove all overrides from this device?')) return;
          try { await api.patch(`/admin/devices/${id}`, { clear_overrides: true }); toast('Overrides removed.'); route(); } catch (ex) { fail(ex); }
        },
      }, 'Remove all overrides')) : null);

  // Events
  const evs = await api.get(`/admin/devices/${id}/events?limit=50`);
  const eventsCard = h('div', { class: 'card' }, h('h2', {}, 'Events'),
    evs.items.length ? h('div', { class: 'table-wrap' }, h('table', {}, h('tbody', {}, evs.items.map((e) => h('tr', {},
      h('td', {}, h('code', {}, e.type)),
      h('td', { class: 'trunc', title: e.data ? JSON.stringify(e.data) : '' }, e.data ? JSON.stringify(e.data.data ?? e.data) : ''),
      h('td', {}, when(e.created_at))))))) : h('div', { class: 'empty' }, 'No events yet.'));

  // Danger zone
  const danger = h('div', { class: 'card' }, h('h2', {}, 'Remove from management'));
  if (d.status === 'active' && can('operator')) {
    danger.append(h('p', { class: 'sub' }, 'Retiring releases the device at its next check-in: it forgets this server and its lockdown, and keeps working as a normal Kiosky. To manage it again, enroll it again.'),
      h('button', {
        class: 'danger',
        onclick: async () => {
          if (!window.confirm(`Retire ${d.name}? It will leave management at its next check-in.`)) return;
          try { await api.del(`/admin/devices/${id}`); toast('Device retired.'); route(); } catch (ex) { fail(ex); }
        },
      }, 'Retire device'));
  } else if (d.status === 'retired' && can('admin')) {
    danger.append(h('p', { class: 'sub' }, `Retired ${dateTime(d.retired_at)}. Deleting removes its record, commands and events for good.`),
      h('button', {
        class: 'danger',
        onclick: async () => {
          if (!window.confirm(`Delete ${d.name} permanently?`)) return;
          try { await api.del(`/admin/devices/${id}?purge=1`); toast('Deleted.'); location.hash = '#/devices'; } catch (ex) { fail(ex); }
        },
      }, 'Delete record'));
  } else danger.hidden = true;

  mainEl.append(h('div', { class: 'stack' },
    h('div', { class: 'grid grid-2' }, statusCard, infoCard),
    cmdCard, liveCard, shotsCard, healthCard, policyCard, eventsCard, danger));
  await loadCommands();
  every(20000, () => loadCommands().catch(() => {}));
}

function uptimeCell(v) {
  if (v == null) return '—';
  return v < 90 ? h('span', { class: 'chip warn', title: 'Online less than 90% of the time' }, `${v}%`) : `${v}%`;
}

/* Small single-series charts, drawn as SVG with DOM methods (the CSP allows no inline styles) */
const SVG_NS = 'http://www.w3.org/2000/svg';
function svg(tag, attrs, ...children) {
  const el = document.createElementNS(SVG_NS, tag);
  for (const [k, v] of Object.entries(attrs || {})) if (v !== null && v !== undefined) el.setAttribute(k, String(v));
  for (const c of children.flat()) if (c) el.append(c);
  return el;
}

// One metric over the days: bars from a zero baseline, or a line with dots.
// Every day gets a full-height hover target with a native tooltip.
function miniChart({ title, days, value, format, kind, min, max, note }) {
  // Drawn at roughly its on-screen size (cards are ~300–400 px wide) so 10px labels stay legible
  const W = 340, H = 120, top = 8, bottom = 18, left = 34, right = 6;
  const n = days.length;
  const step = (W - left - right) / n;
  const y = (v) => top + (H - top - bottom) * (1 - (v - min) / (max - min));
  const g = svg('g', {});
  // Recessive grid: top and bottom values only
  for (const v of [min, max]) {
    g.append(svg('line', { x1: left, x2: W - right, y1: y(v), y2: y(v), class: 'grid-line' }),
      svg('text', { x: left - 4, y: y(v) + 3, class: 'axis-label', 'text-anchor': 'end' }, format(v)));
  }
  const pts = [];
  days.forEach((d, i) => {
    const v = value(d);
    const cx = left + step * i + step / 2;
    if (v != null && kind === 'bar') {
      const bw = Math.max(2, step - 2); // 2px surface gap between bars
      const h0 = y(min) - y(Math.max(min, v));
      if (h0 > 0) g.append(svg('path', { class: 'bar', d: roundedBar(cx - bw / 2, y(Math.max(min, v)), bw, h0, Math.min(4, bw / 2)) }));
    }
    if (v != null && kind === 'line') pts.push([cx, y(Math.min(max, Math.max(min, v)))]);
    const hit = svg('rect', { x: left + step * i, y: top, width: step, height: H - top - bottom, class: 'hit' },
      svg('title', {}, `${d.date}: ${v == null ? 'no data' : format(v)}`));
    g.append(hit);
  });
  if (pts.length) {
    // Break the line across days with no data
    let dPath = '';
    let prevI = -2;
    days.forEach((d, i) => {
      const v = value(d);
      if (v == null) return;
      const [px, py] = [left + step * i + step / 2, y(Math.min(max, Math.max(min, v)))];
      dPath += (i === prevI + 1 ? 'L' : 'M') + px.toFixed(1) + ' ' + py.toFixed(1);
      prevI = i;
    });
    g.prepend(svg('path', { class: 'line', d: dPath }));
    for (const [px, py] of pts) g.append(svg('circle', { class: 'dot-mark', cx: px, cy: py, r: 3 }));
    g.querySelectorAll('.hit').forEach((r) => g.append(r)); // hover targets on top
  }
  g.append(svg('text', { x: left, y: H - 4, class: 'axis-label' }, days[0].date.slice(5)),
    svg('text', { x: W - right, y: H - 4, class: 'axis-label', 'text-anchor': 'end' }, 'today'));
  return h('figure', { class: 'mini-chart' }, h('figcaption', {}, title, note ? h('span', { class: 'sub' }, ` ${note}`) : null),
    svg('svg', { viewBox: `0 0 ${W} ${H}`, role: 'img', 'aria-label': title }, g));
}

function roundedBar(x, yTop, w, hgt, r) {
  // Rounded top corners, square at the baseline
  r = Math.min(r, hgt);
  return `M${x} ${yTop + hgt}V${yTop + r}Q${x} ${yTop} ${x + r} ${yTop}H${x + w - r}Q${x + w} ${yTop} ${x + w} ${yTop + r}V${yTop + hgt}Z`;
}

function healthCharts(r) {
  const days = r.days;
  const any = days.some((d) => d.checkins > 0);
  if (!any) return h('div', { class: 'empty' }, 'No check-ins recorded yet. History starts after the 1.4.0 update.');
  const pct = (v) => `${Math.round(v)}%`;
  const wifiVals = days.map((d) => d.wifi_avg).filter((v) => v != null);
  const charts = [
    miniChart({ title: 'Online', note: '(% of each day)', days, value: (d) => d.uptime, format: pct, kind: 'bar', min: 0, max: 100 }),
    miniChart({ title: 'Lowest battery', note: '(%)', days, value: (d) => d.battery_min, format: pct, kind: 'line', min: 0, max: 100 }),
  ];
  if (wifiVals.length) {
    const lo = Math.min(-90, ...wifiVals);
    charts.push(miniChart({ title: 'Wi-Fi signal', note: '(average dBm; higher is better)', days, value: (d) => d.wifi_avg,
      format: (v) => `${Math.round(v)}`, kind: 'line', min: Math.floor(lo / 10) * 10, max: -30 }));
  }
  if (days.some((d) => d.page_errors > 0)) {
    const maxErr = Math.max(...days.map((d) => d.page_errors));
    charts.push(miniChart({ title: 'Page errors', note: '(per day)', days, value: (d) => d.page_errors, format: (v) => `${Math.round(v)}`,
      kind: 'bar', min: 0, max: Math.max(1, maxErr) }));
  }
  const table = h('details', {}, h('summary', { class: 'sub' }, 'Show as a table'),
    h('div', { class: 'table-wrap' }, h('table', {},
      h('thead', {}, h('tr', {}, h('th', {}, 'Day'), h('th', { class: 'num' }, 'Online'), h('th', { class: 'num' }, 'Check-ins'),
        h('th', { class: 'num' }, 'Battery'), h('th', { class: 'num' }, 'Wi-Fi (avg / worst)'), h('th', { class: 'num' }, 'Page errors'))),
      h('tbody', {}, days.slice().reverse().map((d) => h('tr', {},
        h('td', {}, d.date),
        h('td', { class: 'num' }, d.uptime == null ? '—' : `${d.uptime}%`),
        h('td', { class: 'num' }, d.checkins),
        h('td', { class: 'num' }, d.battery_min == null ? '—' : `${d.battery_min}–${d.battery_max}%`),
        h('td', { class: 'num' }, d.wifi_avg == null ? '—' : `${d.wifi_avg} / ${d.wifi_min} dBm`),
        h('td', { class: 'num' }, d.page_errors)))))));
  return h('div', {}, h('div', { class: 'mini-charts' }, charts),
    h('p', { class: 'sub' }, `Days run midnight to midnight, ${r.timezone}.`), table);
}

function liveViewCard(d, editable) {
  const card = h('div', { class: 'card' });
  if (d.status !== 'active') { card.hidden = true; return card; }
  const img = h('div', { class: 'live-frame empty-frame', hidden: true });
  const status = h('div', { class: 'sub' }, 'Streams the tablet\'s screen every couple of seconds. Needs a Kiosky version with live view.');
  const btn = h('button', { class: 'small' }, 'Start live view');
  let running = false;
  let started = 0;
  const queue = (seconds) => api.post(`/admin/devices/${d.id}/commands`, { type: 'live_view', params: { seconds, interval: 2 }, ttl_hours: 1 });
  const stop = async (send = true) => {
    running = false;
    if (state.liveTimer) { clearInterval(state.liveTimer); state.liveTimer = null; }
    btn.textContent = 'Start live view';
    if (send) { try { await queue(0); } catch (e) { /* the device stops by itself */ } }
  };
  btn.addEventListener('click', async () => {
    if (running) { stop(); status.textContent = 'Stopped.'; return; }
    try { await queue(120); } catch (ex) { fail(ex); return; }
    running = true;
    started = Date.now();
    btn.textContent = 'Stop';
    status.textContent = 'Waiting for the tablet to pick up the request (up to 15 s)…';
    img.hidden = false;
    let frame = img;
    let gotFrame = false;
    state.liveTimer = setInterval(() => {
      if (document.hidden) return;
      if (!gotFrame && Date.now() - started > 40000) {
        status.textContent = 'No frames yet. If the command history shows "unsupported command", this Kiosky version can\'t stream; use Take screenshot instead.';
      }
      // Keep it going while someone is watching: renew before the 120 s run ends
      if (Date.now() - started > 100000) { started = Date.now(); queue(120).catch(() => {}); }
      // Load the next frame off-screen and swap it in once it has arrived, so a missing
      // frame (404 until the tablet starts sending) never shows as a broken image.
      // A plain same-origin URL, because the console's CSP doesn't allow blob: images.
      const next = h('img', { class: 'live-frame', alt: `Live view of ${d.name}` });
      next.addEventListener('load', () => {
        if (!running) return;
        frame.replaceWith(next);
        frame = next;
        gotFrame = true;
        status.textContent = `Live · updated ${new Date().toLocaleTimeString()}`;
      });
      next.src = `/api/v1/admin/devices/${d.id}/live?t=${Date.now()}`;
    }, 2000);
  });
  card.append(h('div', { class: 'card-head' }, h('h2', {}, 'Live view'), editable ? btn : null), status, img);
  return card;
}

function renderScreenshots(box, items) {
  clear(box);
  if (!items.length) { box.append(h('div', { class: 'empty' }, 'No screenshots yet.')); return; }
  box.append(h('div', { class: 'shots' }, items.map((c) => {
    const caption = h('div', { class: 'sub' }, when(c.completed_at || c.created_at), c.created_by ? ` · ${c.created_by}` : '');
    if (c.has_attachment) {
      const src = `/api/v1/admin/commands/${c.id}/screenshot`;
      return h('figure', {}, h('a', { href: src, target: '_blank', rel: 'noopener' }, h('img', { src, alt: 'Screenshot', loading: 'lazy' })), caption);
    }
    return h('figure', { class: 'pending' }, h('div', { class: 'shot-placeholder' }, statusChip(c.status),
      c.result && c.result.message ? h('div', { class: 'sub' }, c.result.message) : null), caption);
  })));
}

function renderCommandTable(box, items, showDevice, reload) {
  clear(box);
  if (!items.length) { box.append(h('div', { class: 'empty' }, 'No commands yet.')); return; }
  box.append(h('div', { class: 'table-wrap' }, h('table', {},
    h('thead', {}, h('tr', {}, showDevice ? h('th', {}, 'Device') : null, h('th', {}, 'Command'), h('th', {}, 'Status'),
      h('th', {}, 'Result'), h('th', { class: 'hide-sm' }, 'Sent by'), h('th', { class: 'hide-sm' }, 'Queued'), h('th', {}))),
    h('tbody', {}, items.map((c) => {
      const params = Object.keys(c.params).length ? ' ' + JSON.stringify(c.params) : '';
      const result = [];
      if (c.result && c.result.message) result.push(c.result.message);
      if (c.has_attachment) {
        const src = `/api/v1/admin/commands/${c.id}/screenshot`;
        result.push(h('a', { href: src, target: '_blank', rel: 'noopener' }, h('img', { class: 'shot', src, alt: 'Screenshot', loading: 'lazy' })));
      }
      const cancel = can('operator') && (c.status === 'pending' || c.status === 'delivered')
        ? h('button', {
          class: 'small',
          onclick: async () => { try { await api.del(`/admin/commands/${c.id}`); reload(); } catch (ex) { fail(ex); } },
        }, 'Cancel') : null;
      return h('tr', {},
        showDevice ? h('td', {}, h('a', { href: `#/devices/${c.device_id}` }, c.device_name)) : null,
        h('td', {}, h('code', {}, c.type), h('span', { class: 'sub' }, params)),
        h('td', {}, statusChip(c.status)),
        h('td', {}, result.length ? result : '—'),
        h('td', { class: 'hide-sm' }, c.schedule ? h('a', { href: '#/schedules' }, c.schedule.name) : null,
          c.schedule && c.created_by ? h('div', { class: 'sub' }, c.created_by) : (c.schedule ? null : c.created_by || '—')),
        h('td', { class: 'hide-sm' }, when(c.created_at)),
        h('td', {}, cancel));
    })))));
}

/* ----------------------------------------------------------------- profiles */

async function viewProfiles() {
  const profiles = await api.get('/admin/profiles');
  page('Profiles', can('admin') ? h('a', { class: 'btn', href: '/api/v1/admin/profiles/export', download: '' }, 'Export all') : null);
  const list = h('div', { class: 'card' }, h('div', { class: 'table-wrap' }, h('table', {},
    h('thead', {}, h('tr', {}, h('th', {}, 'Name'), h('th', { class: 'hide-sm' }, 'Description'), h('th', { class: 'num' }, 'Devices'), h('th', { class: 'hide-sm' }, 'Settings'))),
    h('tbody', {}, profiles.map((p) => h('tr', { class: 'clickable', onclick: () => { location.hash = `#/profiles/${p.id}`; } },
      h('td', {}, p.name, p.is_default ? h('span', { class: 'chip' }, 'default') : null),
      h('td', { class: 'hide-sm' }, p.description || '—'),
      h('td', { class: 'num' }, p.device_count),
      h('td', { class: 'hide-sm' }, `${Object.keys(p.settings).length} set`)))))));
  mainEl.append(list);

  if (can('admin')) {
    const name = h('input', { type: 'text', required: true });
    const desc = h('input', { type: 'text' });
    mainEl.append(h('div', { class: 'card stack-gap' },
      h('h2', {}, 'New profile'),
      h('form', {
        onsubmit: async (e) => {
          e.preventDefault();
          try {
            const p = await api.post('/admin/profiles', { name: name.value, description: desc.value, settings: {} });
            location.hash = `#/profiles/${p.id}`;
          } catch (ex) { fail(ex); }
        },
      }, h('div', { class: 'row' }, field('Name', name), field('Description', desc),
        h('div', { class: 'field' }, h('button', { class: 'primary', type: 'submit' }, 'Create'))))));

    const file = h('input', { type: 'file', accept: '.json,application/json', required: true });
    mainEl.append(h('div', { class: 'card stack-gap' },
      h('h2', {}, 'Import profiles'),
      h('p', { class: 'sub' }, 'Load a file made with Export, from this server or another one. Every profile in it is added as a new profile; '
        + 'names already in use get a number added. Pinned release versions must already be uploaded here.'),
      h('form', {
        onsubmit: async (e) => {
          e.preventDefault();
          let doc;
          try { doc = JSON.parse(await file.files[0].text()); } catch (ex) { toast('That file isn\'t valid JSON.', true); return; }
          try {
            const r = await api.post('/admin/profiles/import', doc);
            const skipped = Object.entries(r.skipped_settings);
            toast(`Imported ${r.created.map((c) => c.name).join(', ')}.`
              + (skipped.length ? ` Skipped settings this server doesn't know: ${skipped.map(([n, k]) => `${n}: ${k.join(', ')}`).join('; ')}.` : ''), skipped.length > 0);
            route();
          } catch (ex) { fail(ex); }
        },
      }, h('div', { class: 'row' }, field('Export file', file),
        h('div', { class: 'field' }, h('button', { class: 'primary', type: 'submit' }, 'Import'))))));
  }
}

async function viewProfile(id) {
  const [p, defs, releases, profiles] = await Promise.all([
    api.get(`/admin/profiles/${id}`), schema(), api.get('/admin/releases'), api.get('/admin/profiles'),
  ]);
  const editable = can('admin');
  page(p.name, h('span', { class: 'sub' }, `${p.device_count} device${p.device_count === 1 ? '' : 's'} · policy ${p.policy_version}`),
    editable ? h('a', { class: 'btn', href: `/api/v1/admin/profiles/${id}/export`, download: '' }, 'Export') : null);
  mainEl.querySelector('.page-head').prepend(h('a', { href: '#/profiles', class: 'sub' }, '← Profiles'));

  const name = h('input', { type: 'text', value: p.name, disabled: !editable });
  const desc = h('input', { type: 'text', value: p.description, disabled: !editable });
  const inherited = {};
  for (const def of defs) inherited[def.key] = { value: def.default, from: 'default' };
  const editor = policyEditor({ defs, stored: p.settings, inherited, releases, editable, setLabel: 'Set' });

  const save = async () => {
    try {
      await api.patch(`/admin/profiles/${id}`, { name: name.value, description: desc.value, settings: editor.changes() });
      toast('Profile saved. Devices apply it at their next check-in.');
      route();
    } catch (ex) { fail(ex); }
  };

  mainEl.append(h('div', { class: 'stack' },
    h('div', { class: 'card' }, h('div', { class: 'row' }, field('Name', name), field('Description', desc))),
    h('div', { class: 'card' },
      h('h2', {}, 'Policy'),
      h('p', { class: 'sub' }, 'Ticked settings are enforced on every device in this profile. Unticked settings use the built-in default. Devices can still override individual settings.'),
      editor.el,
      editable ? h('div', { class: 'actions' }, h('button', { class: 'primary', onclick: save }, 'Save profile')) : null),
    profileHistoryCard(id, defs, editable)));

  if (editable && !p.is_default) {
    const moveTo = h('select', {}, profiles.filter((x) => x.id !== p.id).map((x) => h('option', { value: x.id }, x.name)));
    mainEl.append(h('div', { class: 'card stack-gap' }, h('h2', {}, 'Delete profile'),
      h('div', { class: 'row' }, field('Move its devices and enrollment codes to', moveTo),
        h('div', { class: 'field' }, h('button', {
          class: 'danger',
          onclick: async () => {
            if (!window.confirm(`Delete profile ${p.name}?`)) return;
            try { await api.del(`/admin/profiles/${id}?move_to=${moveTo.value}`); toast('Profile deleted.'); location.hash = '#/profiles'; } catch (ex) { fail(ex); }
          },
        }, 'Delete')))));
  }
}

function profileHistoryCard(id, defs, editable) {
  const card = h('div', { class: 'card' }, h('h2', {}, 'History'));
  const labelOf = (key) => (defs.find((d) => d.key === key) || { label: key }).label;
  const ACTIONS = { created: 'Created', saved: 'Saved', imported: 'Imported', restored: 'Restored', baseline: 'Before history began' };
  api.get(`/admin/profiles/${id}/versions`).then((r) => {
    if (!r.items.length) { card.append(h('div', { class: 'empty' }, 'No saved versions yet.')); return; }
    card.append(h('p', { class: 'sub' }, 'Each save is kept. Restore puts the profile back exactly as it was then; devices pick it up at their next check-in.'),
      h('div', { class: 'table-wrap' }, h('table', {},
        h('thead', {}, h('tr', {}, h('th', {}, 'When'), h('th', {}, 'Who'), h('th', {}, 'What changed'), h('th', {}))),
        h('tbody', {}, r.items.map((v) => h('tr', {},
          h('td', {}, when(v.created_at), h('div', { class: 'sub' }, ACTIONS[v.action] || v.action)),
          h('td', {}, v.changed_by || '—'),
          h('td', {}, v.note ? h('div', { class: 'sub' }, v.note) : null,
            v.changes.length ? h('ul', { class: 'changes' }, v.changes.map((c) => h('li', {},
              h('strong', {}, c.key === 'name' ? 'Name' : c.key === 'description' ? 'Description' : labelOf(c.key)), ': ',
              c.change === 'set' ? ['set to ', h('code', {}, fmtValue(c.to))]
                : c.change === 'unset' ? ['back to default (was ', h('code', {}, fmtValue(c.from)), ')']
                  : [h('code', {}, fmtValue(c.from)), ' → ', h('code', {}, fmtValue(c.to))]))) : h('span', { class: 'sub' }, 'No setting changes')),
          h('td', {}, v.is_current ? h('span', { class: 'chip ok' }, 'Current')
            : editable ? h('button', {
              class: 'small',
              onclick: async () => {
                if (!window.confirm(`Restore this profile to how it was ${new Date(v.created_at).toLocaleString()}?`)) return;
                try {
                  const res = await api.post(`/admin/profiles/${id}/versions/${v.id}/restore`);
                  toast(res.warning || 'Restored. Devices apply it at their next check-in.', !!res.warning);
                  route();
                } catch (ex) { fail(ex); }
              },
            }, 'Restore') : null)))))));
  }).catch(() => card.append(h('div', { class: 'empty' }, 'History is available after the 1.4.0 database update.')));
  return card;
}

/* --------------------------------------------------------------- enrollment */

async function viewEnroll() {
  const [profiles, tokens] = await Promise.all([api.get('/admin/profiles'), api.get('/admin/enrollment-tokens')]);
  page('Enrollment');

  const prof = h('select', {}, profiles.map((p) => h('option', { value: p.id }, p.name)));
  const label = h('input', { type: 'text', placeholder: 'e.g. Reception tablets' });
  const prefix = h('input', { type: 'text', placeholder: 'e.g. Lobby' });
  const hours = h('select', {},
    [[24, '1 day'], [72, '3 days'], [168, '1 week'], [720, '30 days'], ['', 'Never']].map(([v, t]) => h('option', { value: v, selected: v === 72 }, t)));
  const uses = h('input', { type: 'number', min: 1, placeholder: 'unlimited' });

  const form = h('form', {
    onsubmit: async (e) => {
      e.preventDefault();
      try {
        await api.post('/admin/enrollment-tokens', {
          profile_id: parseInt(prof.value, 10),
          label: label.value,
          name_prefix: prefix.value,
          expires_hours: hours.value === '' ? null : parseInt(hours.value, 10),
          max_uses: uses.value === '' ? null : parseInt(uses.value, 10),
        });
        toast('Enrollment code created.');
        route();
      } catch (ex) { fail(ex); }
    },
  },
  h('div', { class: 'row' }, field('Profile', prof), field('Label', label), field('Device name prefix', prefix, 'Devices are named Prefix-001, Prefix-002… Leave empty to use the tablet model.')),
  h('div', { class: 'row' }, field('Expires after', hours), field('Max devices', uses),
    h('div', { class: 'field' }, h('button', { class: 'primary', type: 'submit' }, 'Create code'))));

  const howto = h('div', { class: 'card' }, h('h2', {}, 'Enrolling a tablet'),
    h('ol', {},
      h('li', {}, 'Install Kiosky on the tablet and open it.'),
      h('li', {}, 'Open the toolbar (swipe from the top edge) and choose ', h('strong', {}, 'Enroll'), '.'),
      h('li', {}, 'Scan the QR code below, or type the server address and the code.'),
      h('li', {}, 'The tablet locks to its profile\'s policy right away and appears under Devices.')));

  const list = h('div', { class: 'grid grid-2' });
  if (!tokens.length) list.append(h('div', { class: 'card empty' }, 'No active enrollment codes.'));
  for (const t of tokens) {
    let qr;
    try { qr = window.QR.svg(t.enroll_uri); } catch (e) { qr = h('div', { class: 'sub' }, 'QR unavailable'); }
    list.append(h('div', { class: 'card enroll-card' }, qr,
      h('div', {},
        h('div', { class: 'code-big' }, t.code),
        h('div', { class: 'sub' }, t.label || 'Untitled'),
        h('dl', { class: 'kv' },
          h('dt', {}, 'Server'), h('dd', {}, h('code', {}, t.server)),
          h('dt', {}, 'Profile'), h('dd', {}, t.profile_name),
          h('dt', {}, 'Used'), h('dd', {}, `${t.uses}${t.max_uses ? ' of ' + t.max_uses : ''}`),
          h('dt', {}, 'Expires'), h('dd', {}, t.expires_at ? dateTime(t.expires_at) : 'never'),
          t.name_prefix ? [h('dt', {}, 'Names'), h('dd', {}, `${t.name_prefix}-NNN`)] : null),
        h('div', { class: 'actions' },
          h('button', {
            class: 'small',
            onclick: async () => {
              try { await navigator.clipboard.writeText(t.enroll_uri); toast('Enrollment link copied.'); } catch (e) { toast(t.enroll_uri); }
            },
          }, 'Copy link'),
          h('button', {
            class: 'small danger',
            onclick: async () => {
              if (!window.confirm(`Revoke code ${t.code}? Devices already enrolled are not affected.`)) return;
              try { await api.del(`/admin/enrollment-tokens/${t.id}`); route(); } catch (ex) { fail(ex); }
            },
          }, 'Revoke')))));
  }
  mainEl.append(h('div', { class: 'stack' }, h('div', { class: 'grid grid-2' }, h('div', { class: 'card' }, h('h2', {}, 'New enrollment code'), form), howto), list));
}

/* ----------------------------------------------------------------- commands */

async function viewCommands() {
  const statusSel = h('select', {}, ['', 'pending', 'delivered', 'done', 'failed', 'expired', 'cancelled'].map((s) => h('option', { value: s }, s || 'Any status')));
  page('Commands', statusSel);
  const box = h('div', {});
  mainEl.append(h('div', { class: 'card' }, box));
  const load = async () => {
    const r = await api.get('/admin/commands?limit=200' + (statusSel.value ? `&status=${statusSel.value}` : ''));
    renderCommandTable(box, r.items, true, load);
  };
  statusSel.addEventListener('change', () => load().catch(fail));
  await load();
  every(20000, () => load().catch(() => {}));
}

/* ---------------------------------------------------------------- schedules */

const DAY_NAMES = { mon: 'Mon', tue: 'Tue', wed: 'Wed', thu: 'Thu', fri: 'Fri', sat: 'Sat', sun: 'Sun' };

function daysLabel(days) {
  const key = days.join(',');
  if (key === 'mon,tue,wed,thu,fri,sat,sun') return 'Every day';
  if (key === 'mon,tue,wed,thu,fri') return 'Weekdays';
  if (key === 'sat,sun') return 'Weekends';
  return days.map((d) => DAY_NAMES[d]).join(', ');
}

async function viewSchedules() {
  const [r, types, profiles, tags] = await Promise.all([
    api.get('/admin/schedules'), commandTypes(), api.get('/admin/profiles'), api.get('/admin/tags'),
  ]);
  page('Schedules');
  mainEl.append(h('p', { class: 'sub' }, 'Scheduled commands are queued by ', h('code', {}, 'bin/cron.php'),
    ', so they go out up to one cron interval (10 minutes) after the set time. A run missed by more than an hour, because cron wasn\'t running, is skipped.'));
  if (r.cron.stale) {
    mainEl.append(h('div', { class: 'card banner' }, h('div', {}, h('strong', {}, 'Cron isn\'t running. '),
      'Nothing here will run until ', h('code', {}, 'php bin/cron.php'), ' is scheduled every 10 minutes.')));
  }
  // Besides device commands, a schedule can move devices to another profile
  const actions = [{ type: 'set_profile', label: 'Switch to profile', params: { profile_id: { type: 'profile', required: true } } }, ...types];
  const profileName = (pid) => (profiles.find((x) => x.id === pid) || { name: `#${pid}` }).name;
  const typeLabel = (t) => (actions.find((x) => x.type === t) || { label: t }).label;
  const editable = can('operator');

  const list = h('div', { class: 'card' });
  if (!r.items.length) list.append(h('div', { class: 'empty' }, 'No schedules yet.'));
  else {
    list.append(h('div', { class: 'table-wrap' }, h('table', {},
      h('thead', {}, h('tr', {}, h('th', {}, 'Name'), h('th', {}, 'Command'), h('th', {}, 'Devices'), h('th', {}, 'When'),
        h('th', {}, 'Next run'), h('th', { class: 'hide-sm' }, 'Last run'), h('th', {}))),
      h('tbody', {}, r.items.map((s) => {
        const params = s.type === 'set_profile' ? ` ${profileName(s.params.profile_id)}`
          : Object.keys(s.params).length ? ' ' + JSON.stringify(s.params) : '';
        return h('tr', {},
          h('td', {}, s.name, s.enabled ? null : [' ', h('span', { class: 'chip' }, 'Paused')]),
          h('td', {}, typeLabel(s.type), h('span', { class: 'sub' }, params)),
          h('td', {}, s.target_label),
          h('td', {}, daysLabel(s.days), ' at ', s.time, h('div', { class: 'sub' }, s.timezone)),
          h('td', {}, s.enabled && s.next_run_at ? h('span', { title: dateTime(s.next_run_at) }, dateTime(s.next_run_at)) : '—'),
          h('td', { class: 'hide-sm' }, s.last_run_at ? [when(s.last_run_at), h('div', { class: 'sub' }, s.last_result || '')] : 'never'),
          h('td', {}, editable ? h('div', { class: 'actions' },
            h('button', {
              class: 'small',
              onclick: async () => {
                try { const x = await api.post(`/admin/schedules/${s.id}/run`); toast(x.result); route(); } catch (ex) { fail(ex); }
              },
            }, 'Run now'),
            h('button', {
              class: 'small',
              onclick: async () => {
                try { await api.patch(`/admin/schedules/${s.id}`, { enabled: !s.enabled }); route(); } catch (ex) { fail(ex); }
              },
            }, s.enabled ? 'Pause' : 'Resume'),
            h('button', {
              class: 'small danger',
              onclick: async () => {
                if (!window.confirm(`Delete schedule ${s.name}?`)) return;
                try { await api.del(`/admin/schedules/${s.id}`); route(); } catch (ex) { fail(ex); }
              },
            }, 'Delete')) : null));
      })))));
  }
  mainEl.append(list);
  if (!editable) return;

  // New schedule
  const name = h('input', { type: 'text', required: true, placeholder: 'e.g. Nightly reload' });
  const target = h('select', {},
    h('option', { value: 'all' }, 'All devices'),
    h('optgroup', { label: 'Profile' }, profiles.map((p) => h('option', { value: `profile:${p.id}` }, p.name))),
    tags.length ? h('optgroup', { label: 'Tag' }, tags.map((t) => h('option', { value: `tag:${t.tag}` }, `${t.tag} (${t.devices})`))) : null);
  const dayBoxes = Object.keys(DAY_NAMES).map((k) => [k, h('input', { type: 'checkbox', checked: true })]);
  const days = h('div', { class: 'days' }, dayBoxes.map(([k, box]) => h('label', { class: 'check' }, box, DAY_NAMES[k])));
  const time = h('input', { type: 'time', value: '05:00', required: true });
  const tz = h('input', { type: 'text', value: r.timezone, required: true, list: 'tz-list' });
  let tzList = null;
  try {
    tzList = h('datalist', { id: 'tz-list' }, Intl.supportedValuesOf('timeZone').map((z) => h('option', { value: z })));
  } catch (e) { /* older browsers: free text */ }
  const ttl = h('input', { type: 'number', min: 1, max: 720, placeholder: 'server default' });

  const extra = h('div', {},
    h('div', { class: 'row' }, field('Name', name), field('Devices', target)),
    field('Days', days),
    h('div', { class: 'row' }, field('Time', time), field('Time zone', tz, 'For example Europe/London. Daylight saving is handled.'), tzList,
      field('Expires after (hours)', ttl, 'How long an offline device can still pick the command up.')));

  mainEl.append(h('div', { class: 'card stack-gap' }, h('h2', {}, 'New schedule'),
    commandForm(actions, async (type, params) => {
      const [kind, value] = target.value.split(/:(.*)/);
      const t = kind === 'profile' ? { profile_id: parseInt(value, 10) } : kind === 'tag' ? { tag: value } : { all: true };
      await api.post('/admin/schedules', {
        name: name.value,
        type,
        params,
        target: t,
        days: dayBoxes.filter(([, b]) => b.checked).map(([k]) => k),
        time: time.value,
        timezone: tz.value,
        ttl_hours: ttl.value === '' ? null : parseInt(ttl.value, 10),
      });
      toast('Schedule created.');
      route();
    }, { label: 'Create schedule', extra, profiles }),
    h('p', { class: 'sub' }, 'Switch to profile moves the chosen devices to another profile, e.g. a Night profile with a different start page at 22:00 and back to Day at 07:00. Make two schedules for the two directions, targeting a tag.')));
}

/* ----------------------------------------------------------------- releases */

async function viewReleases() {
  const releases = await api.get('/admin/releases');
  page('Releases');
  mainEl.append(h('p', { class: 'sub' }, 'APKs uploaded here are offered to devices whose update source is "server". Profiles choose "latest" or pin a version. '
    + 'Each release is stable or dev: "latest" means the newest stable release, except on devices whose Release channel setting is dev, which get dev builds too. '
    + 'Each APK must be signed with the same key as the installed Kiosky, or Android refuses it.'));

  const list = h('div', { class: 'card' });
  if (!releases.length) list.append(h('div', { class: 'empty' }, 'No releases uploaded. Devices on the "server" source won\'t update until you add one.'));
  else {
    list.append(h('div', { class: 'table-wrap' }, h('table', {},
      h('thead', {}, h('tr', {}, h('th', {}, 'Version'), h('th', {}, 'Channel'), h('th', { class: 'num hide-sm' }, 'Code'), h('th', { class: 'hide-sm' }, 'Size'), h('th', { class: 'hide-sm' }, 'SHA-256'),
        h('th', { class: 'num hide-sm' }, 'Devices on it'), h('th', { class: 'num hide-sm', title: 'Devices whose profile, ring or override points at this release' }, 'Targeted'),
        h('th', { class: 'hide-sm' }, 'Uploaded'), h('th', {}, 'Offered'), h('th', {}))),
      h('tbody', {}, releases.map((r) => h('tr', {},
        h('td', {}, h('strong', {}, r.version_name), r.notes ? h('div', { class: 'sub' }, r.notes) : null),
        h('td', {}, r.channel === 'dev' ? h('span', { class: 'chip warn', title: 'Only devices on the dev channel get this as "latest"' }, 'Dev')
          : h('span', { class: 'chip ok' }, 'Stable')),
        h('td', { class: 'num hide-sm' }, r.version_code),
        h('td', { class: 'hide-sm' }, bytes(r.size)),
        h('td', { class: 'hide-sm' }, h('code', { title: r.sha256 }, r.sha256.slice(0, 12) + '…')),
        h('td', { class: 'num hide-sm' }, r.devices_on_version ?? 0),
        h('td', { class: 'num hide-sm' }, r.devices_targeted ?? 0),
        h('td', { class: 'hide-sm' }, when(r.created_at), r.uploaded_by ? h('div', { class: 'sub' }, r.uploaded_by) : null),
        h('td', {}, r.active ? h('span', { class: 'chip ok' }, 'Yes') : h('span', { class: 'chip' }, 'Withdrawn')),
        h('td', {}, can('admin') ? h('div', { class: 'actions' },
          h('button', {
            class: 'small',
            onclick: async () => { try { await api.patch(`/admin/releases/${r.id}`, { active: !r.active }); route(); } catch (ex) { fail(ex); } },
          }, r.active ? 'Withdraw' : 'Offer'),
          h('button', {
            class: 'small',
            title: r.channel === 'dev' ? 'Offer it to every device on "latest"' : 'Offer it only to devices on the dev channel',
            onclick: async () => {
              try {
                await api.patch(`/admin/releases/${r.id}`, { channel: r.channel === 'dev' ? 'stable' : 'dev' });
                toast(r.channel === 'dev' ? `${r.version_name} is now stable.` : `${r.version_name} is now a dev build.`);
                route();
              } catch (ex) { fail(ex); }
            },
          }, r.channel === 'dev' ? 'Promote to stable' : 'Move to dev'),
          h('button', {
            class: 'small danger',
            onclick: async () => {
              if (!window.confirm(`Delete release ${r.version_name}?`)) return;
              try { await api.del(`/admin/releases/${r.id}`); route(); } catch (ex) { fail(ex); }
            },
          }, 'Delete')) : null)))))));
  }
  mainEl.append(list, releaseRingsCard(releases));

  if (can('admin')) {
    const file = h('input', { type: 'file', accept: '.apk,application/vnd.android.package-archive', required: true });
    const notes = h('input', { type: 'text' });
    const channel = h('select', {}, h('option', { value: 'stable' }, 'Stable: every device on "latest"'),
      h('option', { value: 'dev' }, 'Dev: only devices on the dev channel'));
    const vName = h('input', { type: 'text', placeholder: 'read from APK' });
    const vCode = h('input', { type: 'number', placeholder: 'read from APK' });
    const button = h('button', { class: 'primary', type: 'submit' }, 'Upload');
    const advanced = h('details', {}, h('summary', { class: 'sub' }, 'Version (only if the server can\'t read APKs)'),
      h('div', { class: 'row' }, field('Version name', vName), field('Version code', vCode)));
    mainEl.append(h('div', { class: 'card stack-gap' }, h('h2', {}, 'Upload a release'),
      h('form', {
        onsubmit: async (e) => {
          e.preventDefault();
          const fd = new FormData();
          fd.append('apk', file.files[0]);
          fd.append('notes', notes.value);
          fd.append('channel', channel.value);
          if (vName.value) fd.append('version_name', vName.value);
          if (vCode.value) fd.append('version_code', vCode.value);
          button.disabled = true;
          button.textContent = 'Uploading…';
          try { const r = await api.upload('/admin/releases', fd); toast(`Uploaded Kiosky ${r.version_name}.`); route(); } catch (ex) { fail(ex); } finally {
            button.disabled = false;
            button.textContent = 'Upload';
          }
        },
      }, field('APK file', file), field('Release notes', notes),
      field('Channel', channel, 'Upload test builds as dev, try them on a few tablets (set their Release channel to dev, or use a ring), then promote to stable.'),
      advanced, button)));
  }
}

// Staged rollouts: a release per tag, e.g. "canary" gets the newest APK before everyone else
function releaseRingsCard(releases) {
  const card = h('div', { class: 'card stack-gap' }, h('h2', {}, 'Staged rollouts'),
    h('p', { class: 'sub' }, 'Give devices with a tag their own release, ahead of (or behind) their profile. Tag a few tablets canary, '
      + 'point the canary ring at a new version, watch them update, then move the profiles over. A device\'s own override still wins; '
      + 'a device in several rings follows the newest release.'));
  const box = h('div', {});
  card.append(box);
  const load = async () => {
    const [rings, tags] = await Promise.all([api.get('/admin/releases/rings'), api.get('/admin/tags')]);
    clear(box);
    if (rings.length) {
      box.append(h('div', { class: 'table-wrap' }, h('table', {},
        h('thead', {}, h('tr', {}, h('th', {}, 'Tag'), h('th', {}, 'Release'), h('th', { class: 'num' }, 'Devices'), h('th', {}, 'Progress'), h('th', {}))),
        h('tbody', {}, rings.map((r) => h('tr', {},
          h('td', {}, h('a', { class: 'chip tag', href: `#/devices?tag=${encodeURIComponent(r.tag)}` }, r.tag)),
          h('td', {}, r.release, r.release === 'latest' && r.resolves_to ? h('span', { class: 'sub' }, ` (${r.resolves_to})`) : null),
          h('td', { class: 'num' }, r.devices),
          h('td', {}, r.devices ? `${r.on_release} of ${r.devices} on it` + (r.newer ? `, ${r.newer} newer` : '') : '—'),
          h('td', {}, can('admin') ? h('button', {
            class: 'small danger',
            onclick: async () => {
              if (!window.confirm(`Remove the ${r.tag} ring? Those devices go back to their profile's release.`)) return;
              try { await api.del(`/admin/releases/rings/${encodeURIComponent(r.tag)}`); load().catch(fail); } catch (ex) { fail(ex); }
            },
          }, 'Remove') : null)))))));
    } else box.append(h('div', { class: 'empty' }, 'No rings. Every device follows its profile\'s release.'));
    if (!can('admin')) return;
    const tag = h('input', { type: 'text', list: 'ring-tags', placeholder: 'e.g. canary', required: true });
    const rel = h('select', {}, ['latest', ...releases.map((r) => r.version_name)].map((v) => h('option', { value: v }, v)));
    box.append(h('h3', {}, 'Set a ring'), h('datalist', { id: 'ring-tags' }, tags.map((t) => h('option', { value: t.tag }))),
      h('form', {
        onsubmit: async (e) => {
          e.preventDefault();
          try {
            await api.req('PUT', `/admin/releases/rings/${encodeURIComponent(tag.value.trim().toLowerCase())}`, { release: rel.value });
            toast('Ring saved. Its devices get the release at their next check-in.');
            load().catch(fail);
          } catch (ex) { fail(ex); }
        },
      }, h('div', { class: 'row' }, field('Tag', tag), field('Release', rel),
        h('div', { class: 'field' }, h('button', { class: 'primary', type: 'submit' }, 'Save ring')))));
  };
  load().catch(() => box.append(h('div', { class: 'empty' }, 'Rings are available after the 1.4.0 database update.')));
  return card;
}

/* -------------------------------------------------------------------- users */

async function viewUsers() {
  const users = await api.get('/admin/users');
  page('Users');
  const roleSel = (value, onchange) => h('select', { onchange }, ['viewer', 'operator', 'admin'].map((r) => h('option', { value: r, selected: r === value }, r)));
  mainEl.append(h('div', { class: 'card' }, h('div', { class: 'table-wrap' }, h('table', {},
    h('thead', {}, h('tr', {}, h('th', {}, 'Username'), h('th', { class: 'hide-sm' }, 'Signs in with'), h('th', {}, 'Role'), h('th', { class: 'hide-sm' }, 'Last sign-in'), h('th', {}, 'Status'), h('th', {}))),
    h('tbody', {}, users.map((u) => h('tr', {},
      h('td', {}, u.username, u.id === state.me.id ? h('span', { class: 'sub' }, ' (you)') : null,
        u.email && u.email !== u.username ? h('div', { class: 'sub' }, u.email) : null),
      h('td', { class: 'hide-sm' }, u.sso ? u.sso.label : 'Password',
        !u.sso ? [' ', u.two_factor ? h('span', { class: 'chip ok', title: 'Two-factor sign-in is on' }, '2FA') : h('span', { class: 'chip', title: 'Password only' }, 'no 2FA')] : null),
      h('td', {}, u.role_managed
        ? h('span', { title: `Set by ${u.sso.label} at each sign-in` }, u.role, h('div', { class: 'sub' }, `from ${u.sso.label}`))
        : roleSel(u.role, async (e) => { try { await api.patch(`/admin/users/${u.id}`, { role: e.target.value }); toast('Role changed.'); } catch (ex) { fail(ex); route(); } }),
        u.scope_tags ? h('div', { class: 'sub' }, `only ${u.scope_tags.join(', ')}`) : null),
      h('td', { class: 'hide-sm' }, when(u.last_login_at)),
      h('td', {}, u.disabled ? h('span', { class: 'chip bad' }, 'Disabled') : h('span', { class: 'chip ok' }, 'Active')),
      h('td', {}, h('div', { class: 'actions' },
        u.sso ? null : h('button', {
          class: 'small',
          onclick: async () => {
            const pw = window.prompt(`New password for ${u.username} (at least 10 characters):`);
            if (!pw) return;
            try { await api.patch(`/admin/users/${u.id}`, { password: pw }); toast('Password changed.'); } catch (ex) { fail(ex); }
          },
        }, 'Set password'),
        u.two_factor && u.id !== state.me.id ? h('button', {
          class: 'small',
          onclick: async () => {
            if (!window.confirm(`Turn off two-factor sign-in for ${u.username}? They can then sign in with just their password, and set it up again under Your account.`)) return;
            try { await api.patch(`/admin/users/${u.id}`, { reset_2fa: true }); toast('Two-factor sign-in reset.'); route(); } catch (ex) { fail(ex); }
          },
        }, 'Reset 2FA') : null,
        h('button', {
          class: 'small',
          onclick: async () => { try { await api.patch(`/admin/users/${u.id}`, { disabled: !u.disabled }); route(); } catch (ex) { fail(ex); } },
        }, u.disabled ? 'Enable' : 'Disable'),
        u.role !== 'admin' ? h('button', {
          class: 'small',
          onclick: async () => {
            const v = window.prompt(`Limit ${u.username} to devices with any of these tags (comma separated). Leave empty for every device.`,
              (u.scope_tags || []).join(', '));
            if (v === null) return;
            try { await api.patch(`/admin/users/${u.id}`, { scope_tags: parseTags(v) }); toast('Device access changed.'); route(); } catch (ex) { fail(ex); }
          },
        }, 'Devices…') : null,
        u.id !== state.me.id ? h('button', {
          class: 'small danger',
          onclick: async () => {
            if (!window.confirm(`Delete ${u.username}?`)) return;
            try { await api.del(`/admin/users/${u.id}`); route(); } catch (ex) { fail(ex); }
          },
        }, 'Delete') : null)))))))));

  const name = h('input', { type: 'text', required: true, autocomplete: 'off' });
  const pw = h('input', { type: 'password', required: true, autocomplete: 'new-password', minlength: 10 });
  const role = roleSel('operator');
  const scopeInput = h('input', { type: 'text', placeholder: 'every device' });
  mainEl.append(h('div', { class: 'card stack-gap' }, h('h2', {}, 'Add a user'),
    h('p', { class: 'sub' }, 'viewer: read only. operator: rename devices, change overrides, send commands, create enrollment codes. admin: everything, including profiles, releases and users. '
      + 'Operators and viewers can be limited to devices with certain tags (e.g. one building); they then can\'t see enrollment codes or schedules.'),
    h('p', { class: 'sub' }, 'This adds a password user. Single sign-on users are added automatically the first time they sign in.'),
    h('form', {
      onsubmit: async (e) => {
        e.preventDefault();
        try {
          await api.post('/admin/users', { username: name.value, password: pw.value, role: role.value, scope_tags: parseTags(scopeInput.value) });
          toast('User added.');
          route();
        } catch (ex) { fail(ex); }
      },
    }, h('div', { class: 'row' }, field('Username', name), field('Password', pw), field('Role', role),
      field('Only devices tagged', scopeInput),
      h('div', { class: 'field' }, h('button', { class: 'primary', type: 'submit' }, 'Add'))))));
}

/* ------------------------------------------------------------------- tokens */

async function viewTokens() {
  const tokens = await api.get('/admin/tokens');
  page('API tokens');
  mainEl.append(h('p', { class: 'sub' }, 'Tokens let scripts call the admin API with ', h('code', {}, 'Authorization: Bearer shp_…'), '. A token can never do more than its owner.'));
  const list = h('div', { class: 'card' });
  if (!tokens.length) list.append(h('div', { class: 'empty' }, 'No API tokens.'));
  else {
    list.append(h('div', { class: 'table-wrap' }, h('table', {},
      h('thead', {}, h('tr', {}, h('th', {}, 'Name'), h('th', {}, 'Token'), h('th', {}, 'Role'), h('th', { class: 'hide-sm' }, 'Owner'), h('th', { class: 'hide-sm' }, 'Last used'), h('th', {}, 'Expires'), h('th', {}))),
      h('tbody', {}, tokens.map((t) => h('tr', {},
        h('td', {}, t.name), h('td', {}, h('code', {}, t.prefix + '…')), h('td', {}, t.role), h('td', { class: 'hide-sm' }, t.owner),
        h('td', { class: 'hide-sm' }, when(t.last_used_at)), h('td', {}, t.expires_at ? dateTime(t.expires_at) : 'never'),
        h('td', {}, h('button', {
          class: 'small danger',
          onclick: async () => {
            if (!window.confirm(`Revoke token ${t.name}?`)) return;
            try { await api.del(`/admin/tokens/${t.id}`); route(); } catch (ex) { fail(ex); }
          },
        }, 'Revoke'))))))));
  }
  mainEl.append(list);

  const name = h('input', { type: 'text', required: true });
  const role = h('select', {}, ['viewer', 'operator', 'admin'].filter(can).map((r) => h('option', { value: r, selected: r === state.me.role }, r)));
  const days = h('select', {}, [[30, '30 days'], [90, '90 days'], [365, '1 year'], ['', 'Never']].map(([v, t]) => h('option', { value: v, selected: v === 90 }, t)));
  const out = h('div', { hidden: true });
  mainEl.append(h('div', { class: 'card stack-gap' }, h('h2', {}, 'Create a token'),
    h('form', {
      onsubmit: async (e) => {
        e.preventDefault();
        try {
          const r = await api.post('/admin/tokens', { name: name.value, role: role.value, expires_days: days.value === '' ? null : parseInt(days.value, 10) });
          clear(out).append(h('p', {}, h('strong', {}, 'Copy this token now. It won\'t be shown again.')), h('div', { class: 'secret-box' }, r.token));
          out.hidden = false;
          name.value = '';
        } catch (ex) { fail(ex); }
      },
    }, h('div', { class: 'row' }, field('Name', name), field('Role', role), field('Expires', days),
      h('div', { class: 'field' }, h('button', { class: 'primary', type: 'submit' }, 'Create')))), out));
}

/* -------------------------------------------------------------------- audit */

async function viewAudit() {
  page('Audit log');
  const box = h('div', { class: 'card' });
  mainEl.append(box);
  let before = 0;
  const tbody = h('tbody', {});
  const more = h('button', {}, 'Load more');
  box.append(h('div', { class: 'table-wrap' }, h('table', {},
    h('thead', {}, h('tr', {}, h('th', {}, 'When'), h('th', {}, 'Who'), h('th', {}, 'Action'), h('th', {}, 'Target'), h('th', { class: 'hide-sm' }, 'Details'), h('th', { class: 'hide-sm' }, 'IP'))),
    tbody)), h('div', { class: 'actions' }, more));
  const load = async () => {
    const r = await api.get('/admin/audit?limit=100' + (before ? `&before=${before}` : ''));
    for (const a of r.items) {
      tbody.append(h('tr', {},
        h('td', { title: dateTime(a.created_at) }, ago(a.created_at)),
        h('td', {}, a.actor, h('div', { class: 'sub' }, a.actor_type)),
        h('td', {}, h('code', {}, a.action)),
        h('td', {}, a.target_type === 'device' ? h('a', { href: `#/devices/${a.target_id}` }, `device ${a.target_id}`)
          : a.target_type ? `${a.target_type} ${a.target_id ?? ''}` : '—'),
        h('td', { class: 'trunc hide-sm', title: a.details ? JSON.stringify(a.details) : '' }, a.details ? JSON.stringify(a.details) : ''),
        h('td', { class: 'hide-sm' }, a.ip || '')));
      before = a.id;
    }
    more.hidden = r.items.length < 100;
  };
  more.addEventListener('click', () => load().catch(fail));
  await load();
}

/* ------------------------------------------------------------------ account */

async function viewAccount() {
  page('Your account');
  if (state.me.sso) {
    mainEl.append(h('div', { class: 'card' }, h('h2', {}, 'Single sign-on'),
      h('p', {}, `Signed in as ${state.me.username} (${state.me.role}) with ${state.me.sso.label}. `,
        'Your password, two-factor sign-in and, if roles come from there, your role are managed by your organisation\'s identity provider.')));
    return;
  }
  const cur = h('input', { type: 'password', autocomplete: 'current-password', required: true });
  const nw = h('input', { type: 'password', autocomplete: 'new-password', required: true, minlength: 10 });
  mainEl.append(h('div', { class: 'stack' }, h('div', { class: 'card' }, h('h2', {}, 'Change password'),
    h('p', { class: 'sub' }, `Signed in as ${state.me.username} (${state.me.role}).`),
    h('form', {
      onsubmit: async (e) => {
        e.preventDefault();
        try {
          await api.post('/admin/session/password', { current_password: cur.value, new_password: nw.value });
          toast('Password changed.');
          cur.value = ''; nw.value = '';
        } catch (ex) { fail(ex); }
      },
    }, h('div', { class: 'row' }, field('Current password', cur), field('New password', nw, 'At least 10 characters.'),
      h('div', { class: 'field' }, h('button', { class: 'primary', type: 'submit' }, 'Change'))))),
  twoFactorCard()));
}

function recoveryCodesBox(codes) {
  return h('div', {}, h('p', {}, h('strong', {}, 'Save these recovery codes now. They won\'t be shown again. '),
    'Each one signs you in once if you lose your phone.'),
  h('div', { class: 'secret-box recovery' }, codes.map((c) => h('div', {}, c))));
}

function twoFactorCard() {
  const card = h('div', { class: 'card' }, h('h2', {}, 'Two-factor sign-in'));
  const body = h('div', {});
  card.append(body);

  const passwordAction = (label, cls, run) => {
    const pw = h('input', { type: 'password', autocomplete: 'current-password', required: true });
    return h('form', { onsubmit: async (e) => { e.preventDefault(); try { await run(pw.value); } catch (ex) { fail(ex); } } },
      h('div', { class: 'row' }, field('Your password', pw), h('div', { class: 'field' }, h('button', { class: cls, type: 'submit' }, label))));
  };

  const showOn = () => {
    clear(body).append(
      h('p', {}, h('span', { class: 'chip ok' }, 'On'), ' Signing in needs your password and a code from your authenticator app. ',
        `${state.me.recovery_codes_left ?? 0} recovery code${state.me.recovery_codes_left === 1 ? '' : 's'} left.`),
      h('h3', {}, 'New recovery codes'),
      passwordAction('Make new codes', '', async (password) => {
        const r = await api.post('/admin/session/2fa/recovery', { password });
        state.me.recovery_codes_left = r.recovery_codes.length;
        showOn();
        body.append(recoveryCodesBox(r.recovery_codes));
      }),
      h('h3', {}, 'Turn off'),
      passwordAction('Turn off two-factor sign-in', 'danger', async (password) => {
        await api.post('/admin/session/2fa/disable', { password });
        state.me.two_factor = false;
        toast('Two-factor sign-in is off.');
        showOff();
      }));
  };

  const showOff = () => {
    const start = h('button', { class: 'primary' }, 'Set up');
    start.addEventListener('click', async () => {
      let setup;
      try { setup = await api.post('/admin/session/2fa/setup'); } catch (ex) { fail(ex); return; }
      let qr;
      try { qr = window.QR.svg(setup.uri); } catch (e) { qr = null; }
      const code = h('input', { type: 'text', inputmode: 'numeric', autocomplete: 'one-time-code', required: true, maxlength: 6, class: 'code-input' });
      clear(body).append(
        h('ol', {},
          h('li', {}, 'Open an authenticator app (Microsoft Authenticator, Google Authenticator, 1Password, Bitwarden…) and scan this code.'),
          h('li', {}, 'Type the 6-digit code it shows.')),
        h('div', { class: 'enroll-card' }, qr,
          h('div', {}, h('div', { class: 'sub' }, 'Can\'t scan? Enter this key by hand:'), h('div', { class: 'secret-box' }, setup.secret.match(/.{1,4}/g).join(' ')))),
        h('form', {
          onsubmit: async (e) => {
            e.preventDefault();
            try {
              const r = await api.post('/admin/session/2fa/enable', { code: code.value });
              state.me.two_factor = true;
              state.me.recovery_codes_left = r.recovery_codes.length;
              toast('Two-factor sign-in is on.');
              showOn();
              body.append(recoveryCodesBox(r.recovery_codes));
            } catch (ex) { fail(ex); }
          },
        }, h('div', { class: 'row' }, field('Code from the app', code),
          h('div', { class: 'field' }, h('div', { class: 'actions' },
            h('button', { class: 'primary', type: 'submit' }, 'Turn on'),
            h('button', { type: 'button', onclick: showOff }, 'Cancel'))))));
      code.focus();
    });
    clear(body).append(
      h('p', {}, h('span', { class: 'chip warn' }, 'Off'), ' Add a code from an authenticator app on your phone to your password, so a stolen password alone can\'t get in.'),
      start);
  };

  if (state.me.two_factor) showOn(); else showOff();
  return card;
}

/* -------------------------------------------------------------------- start */

function signedIn(r) {
  api.csrf = r.csrf_token;
  state.me = r.user;
  state.server = r.server;
  start();
}

async function start() {
  renderShell();
  startSideRefresh();
  await route();
}

// Keeps the sidebar's "Cron: 4m ago" current on every page: re-render the relative
// time each minute, and fetch fresh server status every 5 minutes.
let sideTimer = null;
function startSideRefresh() {
  if (sideTimer) clearInterval(sideTimer);
  let ticks = 0;
  sideTimer = setInterval(async () => {
    if (!state.me) { clearInterval(sideTimer); sideTimer = null; return; }
    if (document.hidden) return;
    if (++ticks % 5 === 0) {
      try {
        const r = await api.get('/admin/session');
        state.server = r.server;
      } catch (e) { return; }
    }
    refreshCronLine();
  }, 60000);
}

function refreshCronLine() {
  const side = document.getElementById('cron-status');
  if (side && state.server && state.server.cron) clear(side).append(cronLine(state.server.cron));
}

window.addEventListener('hashchange', route);

(async () => {
  try {
    signedIn(await api.get('/admin/session'));
  } catch (e) {
    renderLogin();
  }
})();
