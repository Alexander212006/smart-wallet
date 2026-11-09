import React from 'react';
import { createRoot } from 'react-dom/client';
import { sileo, Toaster } from 'sileo';
import 'sileo/styles.css';

const toaster = document.getElementById('sileo-toaster');

if (toaster) {
    createRoot(toaster).render(React.createElement(Toaster, {
        position: 'top-center',
        theme: 'light',
    }));
}

window.sileoAlert = (type, message) => {
    const options = { title: message, position: 'top-center' };

    if (type === 'success' || type === 'error' || type === 'warning' || type === 'info') {
        sileo[type](options);
        return;
    }

    sileo.show({ ...options, type: 'info' });
};
