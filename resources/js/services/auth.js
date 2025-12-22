import axios from 'axios';

// Ensure cookie is sent for Sanctum (still good for CSRF)
axios.defaults.withCredentials = true;
axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';
// Use Docker container URL when on Vite dev server (port 5173)
const isDevServer = window.location.port === '5173';
axios.defaults.baseURL = isDevServer ? 'http://localhost/api' : '/api';

// Add Interceptor to inject Token
axios.interceptors.request.use((config) => {
    const token = localStorage.getItem('token');
    if (token) {
        config.headers.Authorization = `Bearer ${token}`;
    }
    return config;
});

export const authService = {
    async getCsrf() {
        await axios.get('/sanctum/csrf-cookie', { baseURL: '/' });
    },

    async login(email, password) {
        await this.getCsrf();
        const res = await axios.post('/auth/login', { email, password });
        return res.data; // content: { access_token, user }
    },

    async logout() {
        await axios.post('/auth/logout');
    },

    async me() {
        const res = await axios.get('/me');
        return res.data;
    }
};
