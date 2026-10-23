/**
 * إعدادات الاتصال بواجهة برمجة التطبيقات (API Configuration) — بوكر فارما
 * مخصص لدعم تشغيل الواجهة عبر الويب أو كتطبيق سطح مكتب Electron بدون تعديل الكود
 */

const API_CONFIG = {
    // عنوان الـ API الأساسي - يمكن تغييره بسهولة عند التشغيل داخل Electron
    BASE_URL: (function() {
        // إذا تم تعريفه مسبقاً في بيئة Electron
        if (typeof window !== 'undefined' && window.ELECTRON_API_URL) {
            return window.ELECTRON_API_URL;
        }

        // الكشف التلقائي عند العمل عبر متصفح الويب المحلي أو الخادم
        if (typeof window !== 'undefined' && window.location) {
            const origin = window.location.origin;
            const pathname = window.location.pathname;

            // إذا كان التطبيق يعمل داخل مجلد فرعي في XAMPP
            const subDirMatch = pathname.match(/^(\/[^\/]+)/);
            if (pathname.includes('/public')) {
                const basePath = pathname.substring(0, pathname.indexOf('/public') + 7);
                return `${origin}${basePath}/index.php/api`;
            } else if (subDirMatch && !pathname.startsWith('/api')) {
                // مسار فرعي لمشروع XAMPP
                return `${origin}${subDirMatch[1]}/api`;
            }

            return `${origin}/api`;
        }

        return 'http://127.0.0.1:8000/api';
    })(),

    // وقت الانتظار الأقصى للطلب (Timeout) بالمللي ثانية
    TIMEOUT_MS: 15000,

    // اسم مفتاح تخزين رمز الدخول (Token)
    TOKEN_STORAGE_KEY: 'poker_pharma_token',
    USER_STORAGE_KEY: 'poker_pharma_user',

    /**
     * دالة مساعدة موحدة لتنفيذ طلبات Fetch
     */
    async fetchApi(endpoint, options = {}) {
        const url = endpoint.startsWith('http') ? endpoint : `${this.BASE_URL}${endpoint.startsWith('/') ? '' : '/'}${endpoint}`;
        
        const token = localStorage.getItem(this.TOKEN_STORAGE_KEY);
        
        const defaultHeaders = {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        };

        if (token) {
            defaultHeaders['Authorization'] = `Bearer ${token}`;
        }

        const config = {
            ...options,
            headers: {
                ...defaultHeaders,
                ...(options.headers || {})
            }
        };

        if (config.body && typeof config.body === 'object' && !(config.body instanceof FormData)) {
            config.body = JSON.stringify(config.body);
        }

        try {
            const response = await fetch(url, config);
            const data = await response.json().catch(() => null);

            if (!response.ok) {
                if (response.status === 401) {
                    // انتهاء الجلسة أو غير مسجل
                    localStorage.removeItem(this.TOKEN_STORAGE_KEY);
                    localStorage.removeItem(this.USER_STORAGE_KEY);
                    if (!window.location.pathname.includes('login')) {
                        // إعادة التوجيه لصفحة الدخول إذا لم نكن فيها
                        window.location.href = 'login.html';
                    }
                }
                throw {
                    status: response.status,
                    message: (data && data.message) ? data.message : `خطأ في الخادم (${response.status})`,
                    errors: (data && data.errors) ? data.errors : null
                };
            }

            return data;
        } catch (error) {
            console.error('[API Error]:', error);
            throw error;
        }
    }
};

// إتاحة الكائن في النطاق العام للنافذة
if (typeof window !== 'undefined') {
    window.API_CONFIG = API_CONFIG;
}
