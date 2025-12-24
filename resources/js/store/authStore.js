import { create } from 'zustand';

const useAuthStore = create((set, get) => ({
    user: null,
    permissions: [],
    token: localStorage.getItem('token') || null,
    setUser: (user) => {
        // Extract permissions from user object if present
        const permissions = user?.permissions || [];
        set({ user, permissions });
    },
    setToken: (token) => {
        if (token) {
            localStorage.setItem('token', token);
        } else {
            localStorage.removeItem('token');
        }
        set({ token });
    },
    logout: () => {
        localStorage.removeItem('token');
        set({ user: null, token: null, permissions: [] });
    },
    // Check if user has a specific permission
    hasPermission: (permission) => {
        const { permissions } = get();
        return permissions.includes(permission);
    },
}));

export default useAuthStore;
