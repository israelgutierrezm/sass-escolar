import '../css/app.css';
import './bootstrap';

import { createApp, h, type DefineComponent } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';

const nombreApp = import.meta.env.VITE_APP_NAME || 'Acadion';

createInertiaApp({
    title: (titulo) => (titulo ? `${titulo} · ${nombreApp}` : nombreApp),

    resolve: (name) => {
        const paginas = import.meta.glob<DefineComponent>('./Pages/**/*.vue', { eager: true });

        return paginas[`./Pages/${name}.vue`];
    },

    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) })
            .use(plugin)
            .mount(el);
    },

    progress: {
        color: '#33417A',
    },
});
