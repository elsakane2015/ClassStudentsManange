import axios from 'axios';

// Ensure cookie is sent for Sanctum
axios.defaults.withCredentials = true;
axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';
axios.defaults.baseURL = '/api'; // Relative for deployed app, or absolute if dev

export const authService = {
    async getCsrf() {
        // Sanctum CSRF cookie
        await axios.get('/sanctum/csrf-cookie', { baseURL: '/' });
    },

    async login(email, password) {
        await this.getCsrf();
        const res = await axios.post('/auth/login', { email, password });
        return res.data;
    },

    async logout() {
        await axios.post('/auth/logout');
    },

    async me() {
        const res = await axios.get('/me');
        return res.data;
    }
};
