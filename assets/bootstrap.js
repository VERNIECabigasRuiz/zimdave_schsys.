// Not a Stimulus controller: it sets the CSRF cookie when forms are submitted
import './controllers/csrf_protection_controller.js';
import { registerControllers, startStimulusApp } from 'vite-plugin-symfony/stimulus/helpers';
import { createVueComponentResolver } from '../src/Core/Presentation/assets/vue/createVueComponentResolver.js';

// Controllers from controllers.json, then the app's own and each module's
const app = startStimulusApp();
registerControllers(
    app,
    import.meta.glob(
        [
            './controllers/*_controller.js',
            '!./controllers/csrf_protection_controller.js',
            '../src/*/Presentation/assets/controllers/*_controller.js',
        ],
        { query: '?stimulus', eager: true },
    ),
);

// Lets {{ vue_component('<Module>/<Path>') }} find each module's Vue components
window.resolveVueComponent = createVueComponentResolver(
    import.meta.glob('../src/*/Presentation/assets/vue/**/*.vue', { eager: true }),
);
