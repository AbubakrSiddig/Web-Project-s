/**
 * المدخل الرئيسي لتطبيق سطح المكتب (Electron Main Process)
 * شركة بوكر فارما للتجارة والاستثمار المحدودة
 */

const { app, BrowserWindow, Menu, dialog, shell } = require('electron');
const path = require('path');
const http = require('http');

let mainWindow = null;
const SERVER_URL = 'http://127.0.0.1:8000';
const APP_TITLE = 'شركة بوكر فارما للتجارة والاستثمار المحدودة — نظام إدارة الأعمال';

function checkBackendHealth(callback) {
    http.get(`${SERVER_URL}/api/health`, (res) => {
        if (res.statusCode === 200) {
            callback(true);
        } else {
            callback(false);
        }
    }).on('error', () => {
        callback(false);
    });
}

function createMainWindow() {
    mainWindow = new BrowserWindow({
        width: 1366,
        height: 850,
        minWidth: 1024,
        minHeight: 700,
        title: APP_TITLE,
        webPreferences: {
            preload: path.join(__dirname, 'preload.js'),
            nodeIntegration: false,
            contextIsolation: true,
            sandbox: false,
        },
        show: false,
    });

    const arabicMenu = Menu.buildFromTemplate([
        {
            label: 'ملف',
            submenu: [
                {
                    label: 'إعادة تحميل الشاشة',
                    accelerator: 'CmdOrCtrl+R',
                    click: () => mainWindow.reload()
                },
                { type: 'separator' },
                {
                    label: 'خروج من النظام',
                    accelerator: 'CmdOrCtrl+Q',
                    click: () => app.quit()
                }
            ]
        },
        {
            label: 'عرض',
            submenu: [
                { label: 'تكبير الشاشة', role: 'togglefullscreen' },
                { label: 'تكبير الحجم', role: 'zoomIn' },
                { label: 'تصغير الحجم', role: 'zoomOut' },
                { label: 'الحجم الافتراضي', role: 'resetZoom' },
                { type: 'separator' },
                {
                    label: 'أدوات المطورين (DevTools)',
                    accelerator: 'F12',
                    click: () => mainWindow.webContents.toggleDevTools()
                }
            ]
        },
        {
            label: 'مساعدة',
            submenu: [
                {
                    label: 'حول بوكر فارما',
                    click: () => {
                        dialog.showMessageBox(mainWindow, {
                            type: 'info',
                            title: 'حول المنظومة',
                            message: 'نظام إدارة أعمال بوكر فارما v0.1 (MVP)',
                            detail: 'شركة بوكر فارما للتجارة والاستثمار المحدودة\nتوزيع الأدوية والمستلزمات الطبية - جمهورية السودان\nجميع الحقوق محفوظة 2026',
                            buttons: ['موافق']
                        });
                    }
                }
            ]
        }
    ]);

    Menu.setApplicationMenu(arabicMenu);

    // التحقق من جاهزية خادم الـ PHP الخلفي
    checkBackendHealth((isOnline) => {
        if (isOnline) {
            mainWindow.loadURL(`${SERVER_URL}/index.html`);
        } else {
            // تحميل الصفحة المحلية
            mainWindow.loadFile(path.join(__dirname, '..', 'public', 'index.html'));
        }
    });

    mainWindow.once('ready-to-show', () => {
        mainWindow.maximize();
        mainWindow.show();
    });

    mainWindow.on('closed', () => {
        mainWindow = null;
    });
}

app.whenReady().then(() => {
    createMainWindow();

    app.on('activate', () => {
        if (BrowserWindow.getAllWindows().length === 0) {
            createMainWindow();
        }
    });
});

app.on('window-all-closed', () => {
    if (process.platform !== 'darwin') {
        app.quit();
    }
});
