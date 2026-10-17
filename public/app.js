'use strict';
const t=window.PulseI18n.t;
window.PulseI18n.init();

const $ = selector => document.querySelector(selector);
const demo = new URLSearchParams(location.search).get('demo') === '1';
let labels = {up: t('Saudável'), slow: t('Lento'), down: t('Indisponível'), pending: t('Sem verificação'), stale: t('Desatualizado'), paused:t('Pausado')};
const expanded = new Set();
let state = {monitors: [], incidents: []};
let csrf = '';
let checking = false;
let editing=null, deleting=null;
let managing=false;

function date(timestamp) {
  return timestamp ? new Date(timestamp * 1000).toLocaleString(window.PulseI18n.locale, {
    day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit'
  }) : t('Ainda não verificado');
}

function element(tag, className, text) {
  const node = document.createElement(tag);
  if (className) node.className = className;
  if (text !== undefined) node.textContent = text;
  return node;
}

function currentStatus(monitor) {
  if (Number(monitor.paused)) return 'paused';
  if (!monitor.latest || (!demo && monitor.last_checked === null)) return 'pending';
  const age = Math.floor(Date.now() / 1000) - Number(monitor.latest.checked_at);
  return age > Math.max(60, Number(monitor.interval_seconds) * 2) ? 'stale' : monitor.latest.status;
}

function demoData() {
  const now = Math.floor(Date.now() / 1000);
  const names = [t('Website institucional'), t('API de pedidos'), t('Portal do cliente'), t('Serviço de pagamentos')];
  // Fixed illustrative samples: no network requests and no generated performance claims.
  const samples = [218, 194, 207, 302, 184, 192, 225, 211, 176, 198, 243, 203, 210, 188, 267, 234];
  const monitors = names.map((name, index) => {
    const history = Array.from({length: 48}, (_, i) => {
      const failure = (index === 2 && i >= 43) || (index === 3 && i === 36);
      const status = failure ? 'down' : index === 1 && i >= 44 ? 'slow' : 'up';
      return {
        status, latency_ms: samples[i % samples.length] + index * 75 + (status === 'slow' ? 1600 : 0),
        checked_at: now - (47 - i) * 1800, http_code: failure ? 503 : 200,
        detail: failure ? t('HTTP 503. Serviço temporariamente indisponível.') : t('Amostra fictícia de demonstração.')
      };
    });
    return {
      id: index + 1, name, url: `https://${['www', 'api', 'portal', 'pay'][index]}.example.com`,
      slow_ms: 1500, interval_seconds: 1800, history, latest: history.at(-1),
      uptime: Math.round(10000 * history.filter(h => h.status !== 'down').length / history.length) / 100
    };
  });
  return {
    monitors, generated_at: now,
    incidents: [
      {name: names[2], opened_at: now - 7200, closed_at: null, detail: t('HTTP 503. Serviço temporariamente indisponível.')},
      {name: names[3], opened_at: now - 19800, closed_at: now - 18000, detail: t('HTTP 503. Serviço temporariamente indisponível.')}
    ]
  };
}

async function request(payload) {
  const response = await fetch('api.php', payload ? {
    method: 'POST', headers: {'Content-Type': 'application/json', 'X-Pulse-Language':window.PulseI18n.language, 'X-CSRF-Token': csrf},
    body: JSON.stringify(payload)
  } : {headers:{'X-Pulse-Language':window.PulseI18n.language}});
  const result = await response.json();
  if (response.status === 401) { location.assign('/login'); throw new Error(t('Sua sessão expirou. Entre novamente.')); }
  if (!response.ok) throw new Error(result.error || t('Não foi possível acessar o serviço.'));
  return result;
}

function render() {
  const monitors = state.monitors;
  const sampled = monitors.filter(m => m.uptime !== null && !Number(m.paused));
  const attention = m => ['slow', 'down', 'stale'].includes(currentStatus(m));
  $('#total').textContent = monitors.length;
  $('#monitor-count').textContent = monitors.length;
  $('#available').textContent = monitors.filter(m => currentStatus(m) === 'up').length;
  $('#attention').textContent = monitors.filter(attention).length;
  $('#uptime').textContent = sampled.length
    ? window.PulseI18n.percent(sampled.reduce((sum, m) => sum + Number(m.uptime), 0) / sampled.length) : '—';

  const query = $('#search').value.toLowerCase();
  const filter = $('#status-filter').value;
  const filtered = monitors.filter(m => (m.name + ' ' + m.url).toLowerCase().includes(query)
    && (filter === 'all' || (filter === 'attention' ? attention(m) : currentStatus(m) === filter)));
  $('#monitors').replaceChildren(...filtered.map(monitorRow));
  $('#empty').hidden = monitors.length > 0;
  if (monitors.length && !filtered.length) {
    $('#monitors').append(element('p', 'no-incidents', t('Nenhum monitor corresponde aos filtros.')));
  }
  $('#incident-list').replaceChildren(...(state.incidents.length
    ? state.incidents.map(incidentRow) : [element('div', 'no-incidents', t('Nenhum incidente registrado.'))]));
  $('#updated').textContent = (demo ? t('Dados demonstrativos · ') : t('Atualizado · ')) + date(state.generated_at);
}

function metric(label, value, unit = '') {
  const cell = element('div', 'metric');
  cell.append(element('span', 'mobile-label', label), document.createTextNode(String(value)));
  if (value !== '—' && unit) cell.append(element('small', '', ' ' + unit));
  return cell;
}

function monitorRow(monitor) {
  const status = currentStatus(monitor);
  const row = element('article', 'monitor-row');
  row.setAttribute('aria-label', monitor.name);
  const main = element('div', 'row-main');
  const name = element('div', 'service-info');
  name.append(element('h3', '', monitor.name), element('span', 'url', monitor.url));
  name.title = monitor.url;
  const stateCell = element('div', 'state-cell');
  stateCell.append(element('span', 'mobile-label', t('Estado')), element('span', 'status ' + status, labels[status]));
  const latency = monitor.latest && monitor.latest.http_code ? window.PulseI18n.number(monitor.latest.latency_ms) : '—';
  const availability = monitor.uptime === null ? '—' : window.PulseI18n.percent(Number(monitor.uptime));
  const checked = element('div', 'checked-at');
  checked.append(element('span', 'mobile-label', t('Última verificação')), document.createTextNode(date(monitor.latest?.checked_at)));
  const actions = element('div', 'row-actions');
  const check = element('button', '', t('Verificar'));
  check.type = 'button';
  check.disabled = demo || checking || managing || status === 'paused';
  check.setAttribute('aria-label', t('Verificar ') + monitor.name);
  check.addEventListener('click', async () => {
    checking = true;
    check.disabled = true;
    check.textContent = t('Verificando…');
    try { await request({action: 'check', id: monitor.id}); await load(); }
    catch (error) { showError(error.message); }
    finally { checking = false; render(); }
  });
  const detailsButton = element('button', 'details-button', t('Detalhes'));
  detailsButton.type = 'button';
  detailsButton.setAttribute('aria-label', t('Detalhes de ') + monitor.name);
  const panel = detailPanel(monitor, status);
  panel.id = 'monitor-details-' + monitor.id;
  panel.hidden = !expanded.has(monitor.id);
  detailsButton.setAttribute('aria-controls', panel.id);
  detailsButton.setAttribute('aria-expanded', String(!panel.hidden));
  detailsButton.addEventListener('click', () => {
    panel.hidden = !panel.hidden;
    if (panel.hidden) expanded.delete(monitor.id); else expanded.add(monitor.id);
    detailsButton.setAttribute('aria-expanded', String(!panel.hidden));
  });
  actions.append(check, detailsButton);
  main.append(name, stateCell, metric(t('Resposta'), latency, t('ms')), metric(t('Disponibilidade · 24h'), availability), checked, actions);
  row.append(main, panel);
  return row;
}

function detailPanel(monitor, status) {
  const panel = element('div', 'detail-panel');
  const meta = element('div', 'detail-meta');
  meta.append(element('span', '', t('Intervalo: ') + window.PulseI18n.number(Number(monitor.interval_seconds) / 60) + ' ' + t('min')),
    element('span', '', t('Limite de resposta: ') + window.PulseI18n.number(monitor.slow_ms) + ' ' + t('ms')),
    element('span', '', monitor.latest ? 'HTTP: ' + (monitor.latest.http_code || t('sem resposta')) : t('Nenhuma resposta registrada')));
  const controls=element('div','management-actions');
  for(const [label,action] of [[t('Editar'),'edit'],[Number(monitor.paused)?t('Retomar'):t('Pausar'),Number(monitor.paused)?'resume':'pause'],[t('Excluir'),'delete']]) {
    const button=element('button',action==='delete'?'danger':'',label);
    button.type='button'; button.disabled=demo || checking || managing;
    button.addEventListener('click',async()=>{
      if(action==='edit'){openForm(monitor);return;}
      if(action==='delete'){openDelete(monitor);return;}
      managing=true; render();
      try {await request({action,id:monitor.id});await load();}
      catch(error){showError(error.message);}
      finally{managing=false;render();}
    });
    controls.append(button);
  }
  panel.append(controls,meta);
  const message = status === 'paused' ? t('Monitor pausado. O histórico foi preservado; nenhuma verificação será executada até a retomada.') : status === 'pending' ? t('Aguardando a primeira verificação.') : status === 'stale'
    ? t('Verificação desatualizada. Confirme se o coletor está em execução; o estado atual do serviço é desconhecido.')
    : t(monitor.latest?.detail || '') || t('Aguardando a primeira verificação.');
  panel.append(element('p', 'detail-message', t(message)));
  if (!monitor.history.length) { panel.append(element('p', 'no-history', t('Sem amostras nas últimas 24 horas.'))); return panel; }
  panel.append(element('h4', 'history-heading', t('Amostras das últimas 24 horas')));
  const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
  svg.setAttribute('viewBox', '0 0 900 65');
  svg.setAttribute('class', 'spark');
  svg.setAttribute('role', 'img');
  svg.setAttribute('aria-label', t('Tempo de resposta; lacunas indicam ausência de resposta HTTP. Escala relativa às amostras exibidas.'));
  const first = Number(monitor.history[0].checked_at);
  const span = Math.max(1, Number(monitor.history.at(-1).checked_at) - first);
  const max = Math.max(1, ...monitor.history.map(h => h.http_code ? h.latency_ms : 0));
  let segment = [];
  function draw() {
    if (segment.length) {
      const path = document.createElementNS(svg.namespaceURI, 'polyline');
      path.setAttribute('points', segment.join(' '));
      path.setAttribute('fill', 'none'); path.setAttribute('stroke', '#52779c'); path.setAttribute('stroke-width', '1.5');
      svg.append(path);
    }
    segment = [];
  }
  for (const sample of monitor.history) {
    if (!sample.http_code) { draw(); continue; }
    segment.push(`${(sample.checked_at - first) / span * 900},${60 - sample.latency_ms / max * 52}`);
  }
  draw();
  panel.append(svg);
  const bars = element('div', 'history');
  for (const sample of monitor.history) {
    const bar = element('span', sample.status);
    bar.title = `${date(sample.checked_at)} · ${labels[sample.status]} · ${sample.http_code ? window.PulseI18n.number(sample.latency_ms) + ' ' + t('ms') : t('sem resposta HTTP')}`;
    bars.append(bar);
  }
  const times = element('div', 'history-labels');
  times.append(element('span', '', date(first)), element('span', '', date(monitor.history.at(-1).checked_at)));
  panel.append(bars, times);
  return panel;
}

function incidentRow(incident) {
  const row = element('div', 'incident-row');
  const summary = element('div');
  summary.append(element('strong', '', incident.name), element('p', '', t(incident.detail)));
  const meta = element('div', 'incident-meta');
  meta.append(element('span', 'incident-state ' + (incident.closed_at ? 'up' : 'down'), incident.closed_at ? t('Resolvido') : t('Em andamento')),
    element('span', '', t('Início: ') + date(incident.opened_at)),
    element('span', '', incident.closed_at ? t('Recuperado: ') + date(incident.closed_at) : t('Aguardando recuperação')));
  row.append(summary, meta);
  return row;
}

function showError(message) { $('#notice').textContent = message; $('#notice').hidden = false; }
async function load() {
  try { state = await request(); csrf = state.csrf; $('#notice').hidden = true; render(); }
  catch (error) { showError(error.message); }
}
function formTitles() {
  $('#dialog-title').textContent=t(editing?'Editar monitor':'Adicionar monitor');
  $('#submit-monitor').textContent=t(editing?'Salvar alterações':'Criar monitor');
}
function openForm(monitor=null) {
  if(demo || managing || checking)return;
  editing=monitor;
  const form=$('#monitor-form'); for(const option of form.querySelectorAll('option[data-custom]')) option.remove(); form.reset();
  // Values come from saved configuration, never from translated text.
  if(monitor) for(const field of ['name','url','interval_seconds','slow_ms','expected_text']) {
    const control=form.elements.namedItem(field);
    if(control.tagName==='SELECT' && !Array.from(control.options).some(o=>o.value===String(monitor[field]))) {
      const option=element('option','',String(monitor[field])+' s');option.value=String(monitor[field]);option.dataset.custom='true';control.append(option);
    }
    control.value=monitor[field];
  }
  formTitles(); $('#form-error').textContent='';$('#monitor-dialog').showModal();
}
function openDelete(monitor) {
  deleting=monitor;$('#delete-form').reset();$('#delete-error').textContent='';
  $('#delete-name').textContent=monitor.name;$('#delete-dialog').showModal();
}
$('#close-delete').addEventListener('click',()=>$('#delete-dialog').close());
$('#cancel-delete').addEventListener('click',()=>$('#delete-dialog').close());
$('#delete-form').addEventListener('submit',async event=>{
  event.preventDefault();if(!deleting || managing)return;
  const confirmation=$('#delete-confirmation').value;
  if(confirmation!==deleting.name){$('#delete-error').textContent=t('Confirme o nome do monitor para excluir.');return;}
  managing=true;$('#submit-delete').disabled=true;
  try{await request({action:'delete',id:deleting.id,confirmation});expanded.delete(deleting.id);$('#delete-dialog').close();deleting=null;await load();}
  catch(error){$('#delete-error').textContent=error.message;}
  finally{managing=false;$('#submit-delete').disabled=false;render();}
});
$('#new-monitor').addEventListener('click', () => openForm());
$('#empty-add').addEventListener('click', () => openForm());
$('#close-dialog').addEventListener('click', () => $('#monitor-dialog').close());
$('#search').addEventListener('input', render);
$('#status-filter').addEventListener('change', render);
$('#monitor-form').addEventListener('submit', async event => {
  event.preventDefault();
  const button = $('#submit-monitor'); button.disabled = true;
  try {
    await request({...Object.fromEntries(new FormData(event.target)),action:editing?'update':'create',...(editing?{id:editing.id}:{})});
    $('#monitor-dialog').close(); event.target.reset(); await load();
  } catch (error) { $('#form-error').textContent = error.message; }
  finally { button.disabled = false; }
});
if (demo) {
  state = demoData(); $('#demo-banner').hidden = false; $('#demo-link').hidden = true;
  $('#new-monitor').disabled = true; render();
} else {
  $('#logout').hidden = false;
  $('#logout').addEventListener('click', async () => {
    try {
      const response = await fetch('auth.php', {method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify({action:'logout'})});
      if (!response.ok) throw new Error(t('Não foi possível sair. Atualize a página e tente novamente.'));
      location.assign('/login');
    } catch (error) { showError(error.message); }
  });
  load();
  setInterval(() => { if (!document.hidden && !$('#monitor-dialog').open && !$('#delete-dialog').open && !checking && !managing) load(); }, 30000);
}

window.addEventListener('pulse:languagechange',()=>{labels={up: t('Saudável'), slow: t('Lento'), down: t('Indisponível'), pending: t('Sem verificação'), stale: t('Desatualizado'), paused:t('Pausado')};if(demo)state=demoData();formTitles();render();});
