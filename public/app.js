'use strict';
const $ = selector => document.querySelector(selector);
const demo = new URLSearchParams(location.search).get('demo') === '1';
let state = {monitors:[], incidents:[]}, csrf = '';
const labels = {up:'Disponível',slow:'Lento',down:'Indisponível',pending:'Sem verificação'};
const date = timestamp => timestamp ? new Date(timestamp * 1000).toLocaleString('pt-BR',{day:'2-digit',month:'2-digit',hour:'2-digit',minute:'2-digit'}) : 'Ainda não verificado';
function element(tag, className, text) { const node = document.createElement(tag); if(className) node.className=className; if(text !== undefined) node.textContent=text; return node; }
function demoData() {
  const now=Math.floor(Date.now()/1000), names=['Website institucional','API de pedidos','Portal do cliente','Serviço de pagamentos'];
  const monitors=names.map((name,index)=>{
    const history=Array.from({length:48},(_,i)=>{const status=index===2 && i>=43?'down':index===1 && i>=44?'slow':'up'; return {status,latency_ms:status==='down'?0:Math.round(180+index*90+Math.sin(i*.8)*60+(status==='slow'?1600:0)),checked_at:now-(47-i)*1800,http_code:status==='down'?503:200,detail:status==='down'?'HTTP 503. Serviço temporariamente indisponível.':'Dados fictícios de demonstração.'};});
    return {id:index+1,name,url:`https://${['www','api','portal','pay'][index]}.example.com`,slow_ms:1500,interval_seconds:300,history,latest:history.at(-1),uptime:Math.round(10000*history.filter(h=>h.status!=='down').length/history.length)/100};
  });
  return {monitors,incidents:[{name:names[2],opened_at:now-7200,closed_at:null,detail:'HTTP 503. Serviço temporariamente indisponível.'},{name:names[3],opened_at:now-21600,closed_at:now-20700,detail:'Tempo limite de conexão.'}],generated_at:now};
}
async function request(payload) {
  const response=await fetch('api.php',payload?{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify(payload)}:{});
  const result=await response.json(); if(!response.ok) throw new Error(result.error || 'Não foi possível acessar o serviço.'); return result;
}
function render() {
  const monitors=state.monitors, checked=monitors.filter(m=>m.uptime!==null);
  $('#total').textContent=monitors.length; $('#monitor-count').textContent=monitors.length;
  $('#available').textContent=monitors.filter(m=>m.latest?.status==='up').length;
  $('#attention').textContent=monitors.filter(m=>['slow','down'].includes(m.latest?.status)).length;
  $('#uptime').textContent=checked.length?(checked.reduce((sum,m)=>sum+Number(m.uptime),0)/checked.length).toFixed(2)+'%':'—';
  const query=$('#search').value.toLowerCase(), filtered=monitors.filter(m=>(m.name+' '+m.url).toLowerCase().includes(query));
  $('#monitors').replaceChildren(...filtered.map(card)); $('#empty').hidden=monitors.length>0;
  if(monitors.length && !filtered.length) $('#monitors').append(element('p','subtle','Nenhum monitor encontrado.'));
  const incidents=state.incidents.map(i=>{const row=element('div','incident-row'), left=element('div'),right=element('div','incident-meta'); left.append(element('strong','',i.name),element('p','',i.detail)); right.append(element('span','badge '+(i.closed_at?'up':'down'),i.closed_at?'Resolvido':'Em andamento'),element('span','',date(i.opened_at)),element('span','',i.closed_at?'Recuperado: '+date(i.closed_at):'Aguardando recuperação')); row.append(left,right);return row;});
  $('#incident-list').replaceChildren(...(incidents.length?incidents:[element('div','no-incidents','Nenhum incidente registrado.') ]));
  $('#updated').textContent=(demo?'Dados demonstrativos · ':'Atualizado · ')+date(state.generated_at);
}
function card(m) {
  const status=m.latest?.status || 'pending', node=element('article','monitor-card');
  const heading=element('div','card-heading'), title=element('div'), badge=element('span','badge '+status); title.append(element('h3','',m.name),element('span','url',m.url)); badge.append(element('i','led'),document.createTextNode(labels[status])); heading.append(title,badge);node.append(heading);
  const metrics=element('div','card-metrics');
  for(const [label,value,unit] of [['Tempo de resposta',m.latest?m.latest.latency_ms:'—','ms'],['Disponibilidade · 24h',m.uptime===null?'—':Number(m.uptime).toFixed(2),'%']]){const cell=element('div'),strong=element('strong','',String(value)); if(value!=='—')strong.append(element('em','', ' '+unit));cell.append(element('small','',label),strong);metrics.append(cell);}node.append(metrics);
  const svg=document.createElementNS('http://www.w3.org/2000/svg','svg');svg.setAttribute('viewBox','0 0 400 44');svg.setAttribute('class','spark');svg.setAttribute('role','img');svg.setAttribute('aria-label','Tempo de resposta das amostras recentes');
  if(m.history.length>1){const max=Math.max(...m.history.map(h=>h.latency_ms),1), line=document.createElementNS(svg.namespaceURI,'polyline');line.setAttribute('points',m.history.map((h,i)=>`${i/(m.history.length-1)*400},${40-h.latency_ms/max*35}`).join(' '));line.setAttribute('fill','none');line.setAttribute('stroke','#8290ff');line.setAttribute('stroke-width','2');svg.append(line);}node.append(svg);
  const history=element('div','history'); if(!m.history.length){for(let i=0;i<48;i++)history.append(element('span','none'));}else for(const check of m.history){const bar=element('span',check.status);bar.title=`${date(check.checked_at)} · ${labels[check.status]} · ${check.latency_ms} ms`;history.append(bar);}node.append(history);
  const times=element('div','history-labels');times.append(element('span','',m.history.length?date(m.history[0].checked_at):'Sem amostras'),element('span','','Mais recente'));node.append(times);
  const bottom=element('div','card-bottom'), info=element('span','',date(m.latest?.checked_at)),button=element('button','','Verificar agora'); button.type='button'; button.disabled=demo;button.addEventListener('click',async()=>{button.disabled=true;button.textContent='Verificando...';try{await request({action:'check',id:m.id});await load();}catch(error){showError(error.message);button.disabled=false;button.textContent='Verificar agora';}});bottom.append(info,button);node.append(bottom);if(m.latest)node.title=m.latest.detail;return node;
}
function showError(message){$('#notice').textContent=message;$('#notice').hidden=false;}
async function load(){try{state=await request();csrf=state.csrf;$('#notice').hidden=true;render();}catch(error){showError(error.message);}}
function openForm(){if(demo)return;$('#form-error').textContent='';$('#monitor-dialog').showModal();}
$('#new-monitor').addEventListener('click',openForm);$('#empty-add').addEventListener('click',openForm);$('#close-dialog').addEventListener('click',()=>$('#monitor-dialog').close());$('#search').addEventListener('input',render);
$('#monitor-form').addEventListener('submit',async event=>{event.preventDefault();const button=$('#submit-monitor');button.disabled=true;try{await request({action:'create',...Object.fromEntries(new FormData(event.target))});$('#monitor-dialog').close();event.target.reset();await load();}catch(error){$('#form-error').textContent=error.message;}finally{button.disabled=false;}});
if(demo){state=demoData();$('#demo-banner').hidden=false;$('#demo-link').hidden=true;$('#new-monitor').disabled=true;render();}else{load();setInterval(()=>{if(!document.hidden && !$('#monitor-dialog').open)load();},30000);}
