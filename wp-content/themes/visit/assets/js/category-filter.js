function agendaFilter() {
    const allEvents = JSON.parse(document.getElementById('agenda-events-data').textContent);
    return {
        events: allEvents,
        allEvents: allEvents,
        activeFilter: null,
        filterByCategory(urn) {
            this.activeFilter = urn;
            this.events = this.allEvents.filter(event => event.tags.includes(urn));
        },
        showAll() {
            this.events = this.allEvents;
            this.activeFilter = null;
        }
    };
}

function categoryFilter() {
    const initialOffers = JSON.parse(document.getElementById('category-offers-data').textContent);
    return {
        offers: initialOffers,
        allOffers: initialOffers,
        activeFilter: null,
        loading: false,
        async filterByCategory(categoryId) {
            this.loading = true;
            this.activeFilter = Number(categoryId);
            try {
                const response = await fetch('/wp-json/pivot/category_items/' + categoryId);
                this.offers = await response.json();
            } catch (e) {
                console.error(e);
            }
            this.loading = false;
        },
        showAll() {
            this.offers = this.allOffers;
            this.activeFilter = null;
        }
    };
}
