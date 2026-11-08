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

const state = { me: null, schema: null, commandTypes: null, timer: null };

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
  ['#/enroll', 'Enrollment', 'operator'],
  ['#/commands', 'Commands', 'viewer'],
  ['#/releases', 'Releases', 'viewer'],
  ['#/users', 'Users', 'admin'],
  ['#/tokens', 'API tokens', 'viewer'],
  ['#/audit', 'Audit log', 'admin'],
];

let mainEl = null;

function renderShell() {
  const app = clear(document.getElementById('app'));
  const nav = h('nav', { class: 'nav' }, NAV.filter(([, , r]) => can(r)).map(([href, label]) => h('a', { href }, label)));
  mainEl = h('main', { id: 'main' });
  app.append(h('div', { class: 'shell' },
    h('aside', { class: 'side' },
      h('div', { class: 'brand' }, h('img', { class: 'brand-mark', src: 'img/logo-128.png', alt: '' }), 'Shepherd'),
      nav,
      h('div', { class: 'side-foot' },
        h('div', {}, h('a', { href: '#/account' }, state.me.username), ' · ', state.me.role),
        h('button', { class: 'small', onclick: logout }, 'Sign out'))),
    mainEl));
}

function highlightNav() {
  const hash = location.hash || '#/overview';
  document.querySelectorAll('.nav a').forEach((a) => {
    a.classList.toggle('active', hash === a.getAttribute('href') || hash.startsWith(a.getAttribute('href') + '/'));
  });
}

function page(title, ...headExtras) {
  clear(mainEl);
  const head = h('div', { class: 'page-head' }, h('h1', {}, title), h('div', { class: 'spacer' }), ...headExtras);
  mainEl.append(head);
  return mainEl;
}

async function renderLogin() {
  stopTimer();
  let setupError = null;
  try {
    const s = await api.get('/admin/setup');
    if (s.needed) { renderSetup(s.enabled, s.install); return; }
  } catch (e) {
    setupError = `The server API isn't answering properly (${e.message}). Open /api/v1/health to check it.`;
  }
  const user = h('input', { type: 'text', autocomplete: 'username', required: true });
  const pass = h('input', { type: 'password', autocomplete: 'current-password', required: true });
  const err = h('div', { class: 'error-text', hidden: true });
  const form = h('form', {
    onsubmit: async (e) => {
      e.preventDefault();
      err.hidden = true;
      try {
        const r = await api.post('/admin/session', { username: user.value, password: pass.value });
        api.csrf = r.csrf_token;
        state.me = r.user;
        start();
      } catch (ex) {
        err.textContent = ex.message;
        err.hidden = false;
      }
    },
  },
  field('Username', user), field('Password', pass), err,
  h('button', { class: 'primary', type: 'submit' }, 'Sign in'));
  clear(document.getElementById('app')).append(h('div', { class: 'login' },
    h('div', { class: 'card' }, logo(), h('h1', {}, 'Kiosky Shepherd'), h('p', { class: 'sub' }, 'Sign in to manage your kiosks.'),
      setupError ? h('p', { class: 'error-text' }, setupError) : null, form)));
  user.focus();
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
            const r = await api.post('/admin/setup', { setup_key: key.value, username: user.value, password: pass.value });
            api.csrf = r.csrf_token;
            state.me = r.user;
            start();
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
}

function every(ms, fn) {
  stopTimer();
  state.timer = setInterval(() => { if (!document.hidden) fn(); }, ms);
}

/* ------------------------------------------------------------------- router */

const ROUTES = [
  [/^#\/overview$/, viewOverview],
  [/^#\/devices$/, viewDevices],
  [/^#\/devices\/(\d+)$/, viewDevice],
  [/^#\/profiles$/, viewProfiles],
  [/^#\/profiles\/(\d+)$/, viewProfile],
  [/^#\/enroll$/, viewEnroll],
  [/^#\/commands$/, viewCommands],
  [/^#\/releases$/, viewReleases],
  [/^#\/users$/, viewUsers],
  [/^#\/tokens$/, viewTokens],
  [/^#\/audit$/, viewAudit],
  [/^#\/account$/, viewAccount],
];

async function route() {
  if (!state.me) return;
  stopTimer();
  const hash = location.hash || '#/overview';
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
    page('Overview', can('operator') ? h('a', { class: 'btn', href: '#/enroll' }, 'Enroll devices') : null);
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
    mainEl.append(h('div', { class: 'grid grid-2' }, attention, h('div', { class: 'stack' }, versions, events)));
  };
  await render();
  every(30000, () => render().catch(() => {}));
}

/* ------------------------------------------------------------------ devices */

function commandForm(types, onSend) {
  const select = h('select', {}, types.map((t) => h('option', { value: t.type }, t.label)));
  const paramsBox = h('div', {});
  let inputs = {};
  const renderParams = () => {
    clear(paramsBox);
    inputs = {};
    const t = types.find((x) => x.type === select.value);
    for (const [name, p] of Object.entries(t.params)) {
      let input;
      if (p.type === 'int') input = h('input', { type: 'number', min: p.min, max: p.max, value: p.default ?? '' });
      else if (p.type === 'string') input = h('textarea', { maxlength: p.max });
      else input = h('input', { type: 'url', placeholder: 'https://' });
      inputs[name] = { input, p };
      paramsBox.append(field(name.replace(/_/g, ' ') + (p.required ? '' : ' (optional)'), input));
    }
  };
  select.addEventListener('change', renderParams);
  renderParams();
  const button = h('button', { class: 'primary', type: 'submit' }, 'Send');
  return h('form', {
    onsubmit: async (e) => {
      e.preventDefault();
      const params = {};
      for (const [name, { input, p }] of Object.entries(inputs)) {
        if (input.value === '') continue;
        params[name] = p.type === 'int' ? parseInt(input.value, 10) : input.value;
      }
      button.disabled = true;
      try { await onSend(select.value, params); } catch (ex) { fail(ex); } finally { button.disabled = false; }
    },
  }, field('Command', select), paramsBox, button);
}

async function viewDevices() {
  const profiles = await api.get('/admin/profiles');
  const q = h('input', { type: 'search', placeholder: 'Search name, model, IP, notes…' });
  const profileSel = h('select', {}, h('option', { value: '' }, 'All profiles'), profiles.map((p) => h('option', { value: p.id }, p.name)));
  const statusSel = h('select', {}, h('option', { value: 'active' }, 'Active'), h('option', { value: 'retired' }, 'Retired'), h('option', { value: 'all' }, 'All'));
  page('Devices', can('operator') ? h('a', { class: 'btn', href: '#/enroll' }, 'Enroll devices') : null);

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
  }

  const load = async () => {
    const params = new URLSearchParams({ status: statusSel.value });
    if (q.value.trim()) params.set('q', q.value.trim());
    if (profileSel.value) params.set('profile_id', profileSel.value);
    const r = await api.get('/admin/devices?' + params);
    clear(tableBox);
    if (!r.items.length) {
      tableBox.append(h('div', { class: 'empty' }, statusSel.value === 'active' && !q.value && !profileSel.value
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
      h('thead', {}, h('tr', {}, can('operator') ? h('th', {}, all) : null, h('th', {}, 'Name'), h('th', {}, 'Profile'),
        h('th', {}, 'Model'), h('th', {}, 'Kiosky'), h('th', {}, 'Page'), h('th', {}, 'Policy'), h('th', {}, 'Last seen'))),
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
          h('td', {}, h('span', { class: 'dot ' + (d.online ? 'ok' : (d.status === 'active' ? 'bad' : '')) }), d.name),
          h('td', {}, d.profile_name),
          h('td', {}, [d.manufacturer, d.model].filter(Boolean).join(' ') || '—'),
          h('td', {}, d.app_version || '—'),
          h('td', { class: 'trunc', title: st.current_url || '' }, st.current_url || '—'),
          h('td', {}, d.status !== 'active' ? '—' : d.policy_current ? h('span', { class: 'chip ok' }, 'Applied') : h('span', { class: 'chip warn' }, 'Pending')),
          h('td', {}, when(d.last_seen_at)));
      })))));
    syncBulk();
  };

  let debounce = null;
  q.addEventListener('input', () => { clearTimeout(debounce); debounce = setTimeout(() => load().catch(fail), 250); });
  profileSel.addEventListener('change', () => load().catch(fail));
  statusSel.addEventListener('change', () => { selected.clear(); load().catch(fail); });

  mainEl.append(h('div', { class: 'stack' },
    h('div', { class: 'row' }, h('div', { class: 'field' }, q), h('div', { class: 'field' }, profileSel), h('div', { class: 'field' }, statusSel)),
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
  const infoCard = h('div', { class: 'card' }, h('h2', {}, 'Device'),
    h('form', {
      onsubmit: async (e) => {
        e.preventDefault();
        try {
          await api.patch(`/admin/devices/${id}`, { name: name.value, profile_id: parseInt(prof.value, 10), notes: notes.value });
          toast('Saved.');
          route();
        } catch (ex) { fail(ex); }
      },
    }, field('Name', name), field('Profile', prof, 'The device gets this profile\'s policy, plus any overrides below.'), field('Notes', notes),
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
  const loadCommands = async () => {
    const r = await api.get(`/admin/commands?device_id=${id}&limit=25`);
    renderCommandTable(cmdList, r.items, false, loadCommands);
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

  // Policy overrides
  const inherited = {};
  for (const def of defs) {
    inherited[def.key] = Object.prototype.hasOwnProperty.call(pol.profile, def.key)
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
    cmdCard, policyCard, eventsCard, danger));
  await loadCommands();
  every(20000, () => loadCommands().catch(() => {}));
}

function renderCommandTable(box, items, showDevice, reload) {
  clear(box);
  if (!items.length) { box.append(h('div', { class: 'empty' }, 'No commands yet.')); return; }
  box.append(h('div', { class: 'table-wrap' }, h('table', {},
    h('thead', {}, h('tr', {}, showDevice ? h('th', {}, 'Device') : null, h('th', {}, 'Command'), h('th', {}, 'Status'),
      h('th', {}, 'Result'), h('th', {}, 'Sent by'), h('th', {}, 'Queued'), h('th', {}))),
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
        h('td', {}, c.created_by || '—'),
        h('td', {}, when(c.created_at)),
        h('td', {}, cancel));
    })))));
}

/* ----------------------------------------------------------------- profiles */

async function viewProfiles() {
  const profiles = await api.get('/admin/profiles');
  page('Profiles');
  const list = h('div', { class: 'card' }, h('div', { class: 'table-wrap' }, h('table', {},
    h('thead', {}, h('tr', {}, h('th', {}, 'Name'), h('th', {}, 'Description'), h('th', { class: 'num' }, 'Devices'), h('th', {}, 'Settings'))),
    h('tbody', {}, profiles.map((p) => h('tr', { class: 'clickable', onclick: () => { location.hash = `#/profiles/${p.id}`; } },
      h('td', {}, p.name, p.is_default ? h('span', { class: 'chip' }, 'default') : null),
      h('td', {}, p.description || '—'),
      h('td', { class: 'num' }, p.device_count),
      h('td', {}, `${Object.keys(p.settings).length} set`)))))));
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
  }
}

async function viewProfile(id) {
  const [p, defs, releases, profiles] = await Promise.all([
    api.get(`/admin/profiles/${id}`), schema(), api.get('/admin/releases'), api.get('/admin/profiles'),
  ]);
  const editable = can('admin');
  page(p.name, h('span', { class: 'sub' }, `${p.device_count} device${p.device_count === 1 ? '' : 's'} · policy ${p.policy_version}`));
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
      editable ? h('div', { class: 'actions' }, h('button', { class: 'primary', onclick: save }, 'Save profile')) : null)));

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

/* ----------------------------------------------------------------- releases */

async function viewReleases() {
  const releases = await api.get('/admin/releases');
  page('Releases');
  mainEl.append(h('p', { class: 'sub' }, 'APKs uploaded here are offered to devices whose update source is "server". Profiles choose "latest" or pin a version. Each APK must be signed with the same key as the installed Kiosky, or Android refuses it.'));

  const list = h('div', { class: 'card' });
  if (!releases.length) list.append(h('div', { class: 'empty' }, 'No releases uploaded. Devices on the "server" source won\'t update until you add one.'));
  else {
    list.append(h('div', { class: 'table-wrap' }, h('table', {},
      h('thead', {}, h('tr', {}, h('th', {}, 'Version'), h('th', { class: 'num' }, 'Code'), h('th', {}, 'Size'), h('th', {}, 'SHA-256'),
        h('th', { class: 'num' }, 'Devices on it'), h('th', {}, 'Uploaded'), h('th', {}, 'Offered'), h('th', {}))),
      h('tbody', {}, releases.map((r) => h('tr', {},
        h('td', {}, h('strong', {}, r.version_name), r.notes ? h('div', { class: 'sub' }, r.notes) : null),
        h('td', { class: 'num' }, r.version_code),
        h('td', {}, bytes(r.size)),
        h('td', {}, h('code', { title: r.sha256 }, r.sha256.slice(0, 12) + '…')),
        h('td', { class: 'num' }, r.devices_on_version ?? 0),
        h('td', {}, when(r.created_at), r.uploaded_by ? h('div', { class: 'sub' }, r.uploaded_by) : null),
        h('td', {}, r.active ? h('span', { class: 'chip ok' }, 'Yes') : h('span', { class: 'chip' }, 'Withdrawn')),
        h('td', {}, can('admin') ? h('div', { class: 'actions' },
          h('button', {
            class: 'small',
            onclick: async () => { try { await api.patch(`/admin/releases/${r.id}`, { active: !r.active }); route(); } catch (ex) { fail(ex); } },
          }, r.active ? 'Withdraw' : 'Offer'),
          h('button', {
            class: 'small danger',
            onclick: async () => {
              if (!window.confirm(`Delete release ${r.version_name}?`)) return;
              try { await api.del(`/admin/releases/${r.id}`); route(); } catch (ex) { fail(ex); }
            },
          }, 'Delete')) : null)))))));
  }
  mainEl.append(list);

  if (can('admin')) {
    const file = h('input', { type: 'file', accept: '.apk,application/vnd.android.package-archive', required: true });
    const notes = h('input', { type: 'text' });
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
          if (vName.value) fd.append('version_name', vName.value);
          if (vCode.value) fd.append('version_code', vCode.value);
          button.disabled = true;
          button.textContent = 'Uploading…';
          try { const r = await api.upload('/admin/releases', fd); toast(`Uploaded Kiosky ${r.version_name}.`); route(); } catch (ex) { fail(ex); } finally {
            button.disabled = false;
            button.textContent = 'Upload';
          }
        },
      }, field('APK file', file), field('Release notes', notes), advanced, button)));
  }
}

/* -------------------------------------------------------------------- users */

async function viewUsers() {
  const users = await api.get('/admin/users');
  page('Users');
  const roleSel = (value, onchange) => h('select', { onchange }, ['viewer', 'operator', 'admin'].map((r) => h('option', { value: r, selected: r === value }, r)));
  mainEl.append(h('div', { class: 'card' }, h('div', { class: 'table-wrap' }, h('table', {},
    h('thead', {}, h('tr', {}, h('th', {}, 'Username'), h('th', {}, 'Role'), h('th', {}, 'Last sign-in'), h('th', {}, 'Status'), h('th', {}))),
    h('tbody', {}, users.map((u) => h('tr', {},
      h('td', {}, u.username, u.id === state.me.id ? h('span', { class: 'sub' }, ' (you)') : null),
      h('td', {}, roleSel(u.role, async (e) => { try { await api.patch(`/admin/users/${u.id}`, { role: e.target.value }); toast('Role changed.'); } catch (ex) { fail(ex); route(); } })),
      h('td', {}, when(u.last_login_at)),
      h('td', {}, u.disabled ? h('span', { class: 'chip bad' }, 'Disabled') : h('span', { class: 'chip ok' }, 'Active')),
      h('td', {}, h('div', { class: 'actions' },
        h('button', {
          class: 'small',
          onclick: async () => {
            const pw = window.prompt(`New password for ${u.username} (at least 10 characters):`);
            if (!pw) return;
            try { await api.patch(`/admin/users/${u.id}`, { password: pw }); toast('Password changed.'); } catch (ex) { fail(ex); }
          },
        }, 'Set password'),
        h('button', {
          class: 'small',
          onclick: async () => { try { await api.patch(`/admin/users/${u.id}`, { disabled: !u.disabled }); route(); } catch (ex) { fail(ex); } },
        }, u.disabled ? 'Enable' : 'Disable'),
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
  mainEl.append(h('div', { class: 'card stack-gap' }, h('h2', {}, 'Add a user'),
    h('p', { class: 'sub' }, 'viewer: read only. operator: rename devices, change overrides, send commands, create enrollment codes. admin: everything, including profiles, releases and users.'),
    h('form', {
      onsubmit: async (e) => {
        e.preventDefault();
        try { await api.post('/admin/users', { username: name.value, password: pw.value, role: role.value }); toast('User added.'); route(); } catch (ex) { fail(ex); }
      },
    }, h('div', { class: 'row' }, field('Username', name), field('Password', pw), field('Role', role),
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
      h('thead', {}, h('tr', {}, h('th', {}, 'Name'), h('th', {}, 'Token'), h('th', {}, 'Role'), h('th', {}, 'Owner'), h('th', {}, 'Last used'), h('th', {}, 'Expires'), h('th', {}))),
      h('tbody', {}, tokens.map((t) => h('tr', {},
        h('td', {}, t.name), h('td', {}, h('code', {}, t.prefix + '…')), h('td', {}, t.role), h('td', {}, t.owner),
        h('td', {}, when(t.last_used_at)), h('td', {}, t.expires_at ? dateTime(t.expires_at) : 'never'),
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
    h('thead', {}, h('tr', {}, h('th', {}, 'When'), h('th', {}, 'Who'), h('th', {}, 'Action'), h('th', {}, 'Target'), h('th', {}, 'Details'), h('th', {}, 'IP'))),
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
        h('td', { class: 'trunc', title: a.details ? JSON.stringify(a.details) : '' }, a.details ? JSON.stringify(a.details) : ''),
        h('td', {}, a.ip || '')));
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
  const cur = h('input', { type: 'password', autocomplete: 'current-password', required: true });
  const nw = h('input', { type: 'password', autocomplete: 'new-password', required: true, minlength: 10 });
  mainEl.append(h('div', { class: 'card' }, h('h2', {}, 'Change password'),
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
      h('div', { class: 'field' }, h('button', { class: 'primary', type: 'submit' }, 'Change'))))));
}

/* -------------------------------------------------------------------- start */

async function start() {
  renderShell();
  await route();
}

window.addEventListener('hashchange', route);

(async () => {
  try {
    const r = await api.get('/admin/session');
    api.csrf = r.csrf_token;
    state.me = r.user;
    start();
  } catch (e) {
    renderLogin();
  }
})();
