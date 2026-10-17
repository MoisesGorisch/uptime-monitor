'use strict';
const t=window.PulseI18n.t;
window.PulseI18n.init();

let token = '', setup = false;
const form = document.querySelector('#login-form');
const error = document.querySelector('#login-error');
async function initialize() {
  try {
    const response = await fetch('/auth.php',{headers:{'X-Pulse-Language':window.PulseI18n.language}});
    const state = await response.json();
    if (!response.ok) throw new Error(state.error || t('Não foi possível carregar o acesso.'));
    if (state.authenticated) { location.replace('/'); return; }
    token = state.csrf;
    setup = !state.configured;
    if (setup && !state.can_setup) {
      document.querySelector('#login-help').textContent = t('Crie o primeiro acesso abrindo o Pulse no próprio computador servidor.');
      return;
    }
    document.querySelector('#login-heading').textContent = setup ? t('Configure seu acesso') : t('Entrar no Pulse');
    document.querySelector('#login-help').textContent = setup ? t('Crie o usuário administrador. Use uma senha de 12 a 72 bytes; uma frase longa funciona bem. Não há senha padrão.') : t('Use seu acesso para consultar e administrar os monitores.');
    document.querySelector('#confirm-label').hidden = !setup;
    document.querySelector('#confirm-password').required = setup;
    document.querySelector('#password').autocomplete = setup ? 'new-password' : 'current-password';
    document.querySelector('#login-submit').textContent = setup ? t('Criar acesso') : t('Entrar');
    form.hidden = false;
  } catch (failure) { document.querySelector('#login-help').textContent = failure.message; }
}
form.addEventListener('submit', async event => {
  event.preventDefault();
  error.textContent = '';
  const button = document.querySelector('#login-submit');
  const values = Object.fromEntries(new FormData(form));
  if (setup && values.password !== document.querySelector('#confirm-password').value) { error.textContent = t('As senhas precisam ser iguais.'); return; }
  button.disabled = true;
  try {
    const response = await fetch('/auth.php', {method:'POST', headers:{'Content-Type':'application/json','X-Pulse-Language':window.PulseI18n.language,'X-CSRF-Token':token},body:JSON.stringify({action:setup ? 'setup':'login',...values})});
    const result = await response.json();
    if (!response.ok) throw new Error(result.error || t('Não foi possível entrar.'));
    form.reset();
    if (setup) {
      await initialize();
      error.textContent = t('Acesso criado. Entre com seu usuário e senha.');
    } else location.replace('/');
  } catch (failure) { error.textContent = failure.message; }
  finally { button.disabled = false; }
});
initialize();

window.addEventListener('pulse:languagechange',()=>{error.textContent='';initialize();});
