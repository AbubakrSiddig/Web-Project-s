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

        // الكشف التلقائي عند العمل عبر متصفح الويب المحلي أو السحابي
        if (typeof window !== 'undefined' && window.location) {
            const origin = window.location.origin;
            const pathname = window.location.pathname;

            // إذا كان التطبيق يعمل داخل مجلد فرعي في XAMPP (مثل /poker/public)
            if (pathname.includes('/public')) {
                const basePath = pathname.substring(0, pathname.indexOf('/public') + 7);
                return `${origin}${basePath}/index.php/api`;
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

        const controller = new AbortController();
        const timeoutId = setTimeout(() => controller.abort(), this.TIMEOUT_MS);

        const config = {
            ...options,
            signal: options.signal || controller.signal,
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
            clearTimeout(timeoutId);
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
                const err = new Error((data && data.message) ? data.message : `خطأ في الخادم (${response.status})`);
                err.status = response.status;
                err.code = (data && data.code) ? data.code : 'HTTP_ERROR';
                err.errors = (data && data.errors) ? data.errors : null;
                throw err;
            }

            if (!data) {
                throw new Error('استجابة غير صالحة من الخادم (Invalid Response)');
            }

            return data;
        } catch (error) {
            clearTimeout(timeoutId);
            if (error.name === 'AbortError') {
                const timeoutErr = new Error(`انتهت مهلة انتظار الاتصال بالخادم (${Math.round(this.TIMEOUT_MS / 1000)} ثانية). يرجى التحقق من اتصال الإنترنت.`);
                timeoutErr.code = 'TIMEOUT';
                console.error('[API Timeout]:', timeoutErr.message);
                throw timeoutErr;
            }
            console.error('[API Error]:', error);
            throw error;
        }
    }
};

// إتاحة الكائن في النطاق العام للنافذة
if (typeof window !== 'undefined') {
    window.API_CONFIG = API_CONFIG;
}
