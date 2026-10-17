'use strict';

let token = '', setup = false;
const form = document.querySelector('#login-form');
const error = document.querySelector('#login-error');
async function initialize() {
  try {
    const response = await fetch('/auth.php');
    const state = await response.json();
    if (!response.ok) throw new Error(state.error || 'Não foi possível carregar o acesso.');
    if (state.authenticated) { location.replace('/'); return; }
    token = state.csrf;
    setup = !state.configured;
    if (setup && !state.can_setup) {
      document.querySelector('#login-help').textContent = 'Crie o primeiro acesso abrindo o Pulse no próprio computador servidor.';
      return;
    }
    document.querySelector('#login-heading').textContent = setup ? 'Configure seu acesso' : 'Entrar no Pulse';
    document.querySelector('#login-help').textContent = setup ? 'Crie o usuário administrador. Use uma senha de 12 a 72 bytes; uma frase longa funciona bem. Não há senha padrão.' : 'Use seu acesso para consultar e administrar os monitores.';
    document.querySelector('#confirm-label').hidden = !setup;
    document.querySelector('#confirm-password').required = setup;
    document.querySelector('#password').autocomplete = setup ? 'new-password' : 'current-password';
    document.querySelector('#login-submit').textContent = setup ? 'Criar acesso' : 'Entrar';
    form.hidden = false;
  } catch (failure) { document.querySelector('#login-help').textContent = failure.message; }
}
form.addEventListener('submit', async event => {
  event.preventDefault();
  error.textContent = '';
  const button = document.querySelector('#login-submit');
  const values = Object.fromEntries(new FormData(form));
  if (setup && values.password !== document.querySelector('#confirm-password').value) { error.textContent = 'As senhas precisam ser iguais.'; return; }
  button.disabled = true;
  try {
    const response = await fetch('/auth.php', {method:'POST', headers:{'Content-Type':'application/json','X-CSRF-Token':token},body:JSON.stringify({action:setup ? 'setup':'login',...values})});
    const result = await response.json();
    if (!response.ok) throw new Error(result.error || 'Não foi possível entrar.');
    form.reset();
    if (setup) {
      await initialize();
      error.textContent = 'Acesso criado. Entre com seu usuário e senha.';
    } else location.replace('/');
  } catch (failure) { error.textContent = failure.message; }
  finally { button.disabled = false; }
});
initialize();
