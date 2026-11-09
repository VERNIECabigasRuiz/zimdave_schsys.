const MODULE_PATH = /\/src\/([^/]+)\/Presentation\/assets\/vue\/(.+)\.vue$/;

/**
 * Maps eagerly imported .vue modules to names like "Attendance/RollCallGrid"
 * (module, then the path under its Presentation/assets/vue folder).
 */
export function createVueComponentResolver(modules) {
    const components = {};

    for (const [path, module] of Object.entries(modules)) {
        const [, moduleName, componentPath] = path.match(MODULE_PATH);
        components[`${moduleName}/${componentPath}`] = module.default;
    }

    return (name) => {
        if (!(name in components)) {
            throw new Error(`Vue component "${name}" not found. Known: ${Object.keys(components).join(', ')}`);
        }

        return components[name];
    };
}
