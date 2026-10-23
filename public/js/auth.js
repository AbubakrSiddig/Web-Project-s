/**
 * إدارة جلسات المصادقة للمستخدمين — بوكر فارما
 */

const Auth = {
    /**
     * حفظ بيانات جلسة تسجيل الدخول
     */
    setSession(token, user, permissions) {
        localStorage.setItem(API_CONFIG.TOKEN_STORAGE_KEY, token);
        localStorage.setItem(API_CONFIG.USER_STORAGE_KEY, JSON.stringify(user));
        localStorage.setItem('poker_pharma_permissions', JSON.stringify(permissions || []));
    },

    /**
     * الحصول على التوكن الحالي
     */
    getToken() {
        return localStorage.getItem(API_CONFIG.TOKEN_STORAGE_KEY);
    },

    /**
     * الحصول على بيانات المستخدم الحالي
     */
    getUser() {
        const userStr = localStorage.getItem(API_CONFIG.USER_STORAGE_KEY);
        try {
            return userStr ? JSON.parse(userStr) : null;
        } catch (e) {
            return null;
        }
    },

    /**
     * الحصول على صلاحيات المستخدم
     */
    getPermissions() {
        const permsStr = localStorage.getItem('poker_pharma_permissions');
        try {
            return permsStr ? JSON.parse(permsStr) : [];
        } catch (e) {
            return [];
        }
    },

    /**
     * فحص هل يمتلك المستخدم صلاحية معينة
     */
    hasPermission(permissionCode) {
        const user = this.getUser();
        if (!user) return false;
        if (user.role_name === 'admin' || user.role_id === 1) return true;
        const permissions = this.getPermissions();
        return permissions.includes(permissionCode);
    },

    /**
     * هل المستخدم مسجل دخوله حالياً
     */
    isAuthenticated() {
        return !!this.getToken() && !!this.getUser();
    },

    /**
     * تنفيذ تسجيل الدخول عبر الـ API
     */
    async login(username, password) {
        const response = await API_CONFIG.fetchApi('/auth/login', {
            method: 'POST',
            body: { username, password }
        });

        if (response && response.success && response.data) {
            this.setSession(
                response.data.token,
                response.data.user,
                response.data.permissions
            );
            return response.data;
        }
        const errMsg = (response && response.message) ? response.message : 'فشل تسجيل الدخول، يرجى التأكد من البيانات';
        throw new Error(errMsg);
    },

    /**
     * تسجيل الخروج
     */
    async logout() {
        try {
            await API_CONFIG.fetchApi('/auth/logout', { method: 'POST' });
        } catch (e) {
            console.warn('Logout API notification failed, clearing local state.');
        } finally {
            localStorage.removeItem(API_CONFIG.TOKEN_STORAGE_KEY);
            localStorage.removeItem(API_CONFIG.USER_STORAGE_KEY);
            localStorage.removeItem('poker_pharma_permissions');
            window.location.href = 'login.html';
        }
    },

    /**
     * التحقق من الجلسة في الصفحات المحمية
     */
    requireAuth() {
        if (!this.isAuthenticated()) {
            window.location.href = 'login.html';
        }
    }
};

window.Auth = Auth;
