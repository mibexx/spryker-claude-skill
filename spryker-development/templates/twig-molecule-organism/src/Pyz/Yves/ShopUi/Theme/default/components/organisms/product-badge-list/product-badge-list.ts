import Component from 'ShopUi/models/component';

export const EVENT_BADGES_TOGGLED = 'badgesToggled';

export default class ProductBadgeList extends Component {
    protected items: HTMLElement[];
    protected toggler: HTMLButtonElement;
    protected isExpanded: boolean;

    protected init(): void {
        this.items = <HTMLElement[]>Array.from(this.getElementsByClassName(`${this.jsName}__item`));
        this.toggler = <HTMLButtonElement>this.getElementsByClassName(`${this.jsName}__toggler`)[0];
        this.isExpanded = false;

        this.mapEvents();
        this.render();
    }

    protected mapEvents(): void {
        if (!this.toggler) {
            return;
        }

        this.toggler.addEventListener('click', (event: Event) => this.onTogglerClick(event));
    }

    protected onTogglerClick(event: Event): void {
        event.preventDefault();

        this.isExpanded = !this.isExpanded;
        this.render();
        this.dispatchCustomEvent(EVENT_BADGES_TOGGLED, { isExpanded: this.isExpanded });
    }

    protected render(): void {
        const hiddenCount = Math.max(this.items.length - this.maxVisible, 0);

        this.items.forEach((item: HTMLElement, index: number) => {
            item.classList.toggle(this.hiddenClassName, !this.isExpanded && index >= this.maxVisible);
        });

        if (!this.toggler) {
            return;
        }

        this.toggler.classList.toggle(this.hiddenClassName, hiddenCount === 0);
        this.toggler.setAttribute('aria-expanded', String(this.isExpanded));
        this.toggler.textContent = this.isExpanded ? '-' : `+${hiddenCount}`;
    }

    protected get maxVisible(): number {
        return Number(this.getAttribute('max-visible'));
    }

    protected get hiddenClassName(): string {
        return this.getAttribute('hidden-class-name');
    }
}
