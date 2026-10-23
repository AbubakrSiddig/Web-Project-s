/**
 * بروتوكول التهيئة المسبقة لتطبيق سطح المكتب (Electron Preload)
 */
const { contextBridge, ipcRenderer } = require('electron');

contextBridge.exposeInMainWorld('ELECTRON_ENV', {
    isElectron: true,
    version: '1.0.0',
    platform: process.platform,
});

window.ELECTRON_API_URL = 'http://127.0.0.1:8000/api';
