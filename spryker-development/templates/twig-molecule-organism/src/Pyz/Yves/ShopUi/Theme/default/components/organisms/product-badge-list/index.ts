import './style.scss';
import register from 'ShopUi/app/registry';
export default register(
    'product-badge-list',
    () =>
        import(
            /* webpackMode: "lazy" */
            /* webpackChunkName: "product-badge-list" */
            './product-badge-list'
        ),
);
