import axios from 'axios';
window.axios = axios;

window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';
window.axios.defaults.withCredentials = true;
// Use Docker container URL when on Vite dev server (port 5173)
// Otherwise use relative path (when accessed via Docker on port 80)
const isDevServer = window.location.port === '5173';
window.axios.defaults.baseURL = isDevServer ? 'http://localhost/api' : '/api';
console.log('[Bootstrap] Axios configured - isDevServer:', isDevServer, 'baseURL:', window.axios.defaults.baseURL);
