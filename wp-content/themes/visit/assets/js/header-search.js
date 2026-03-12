document.addEventListener('alpine:init', () => {
    Alpine.data('headerSearch', () => ({
        // State Management
        isSearchModalOpen: false,
        isMobileMenuOpen: false,
        searchQuery: '',
        results: [],
        isLoading: false,
        error: '',

        init() {
            window.addEventListener('keydown', (event) => {
                if ((event.ctrlKey || event.metaKey) && event.key === 'k') {
                    event.preventDefault();
                    this.openSearchModal();
                }
                if (event.key === 'Escape' && this.isSearchModalOpen) {
                    this.closeSearchModal();
                }
            });
        },

        openSearchModal() {
            this.isSearchModalOpen = true;
        },

        closeSearchModal() {
            this.isSearchModalOpen = false;
            this.searchQuery = '';
            this.results = [];
            this.error = '';
        },

        openMobileMenu() {
            this.isMobileMenuOpen = true;
            document.body.style.overflow = 'hidden';
        },

        closeMobileMenu() {
            this.isMobileMenuOpen = false;
            document.body.style.overflow = '';
        },

        closeOnClickOutside(event) {
            if (!this.isDesktopMenuOpen) return;

            const button = this.$refs.desktopMenuButton;
            const panel = this.$refs.desktopMenuPanel;

            const isClickOnButton = button && button.contains(event.target);
            const isClickInsidePanel = panel && panel.contains(event.target);

            if (!isClickOnButton && !isClickInsidePanel) {
                this.isDesktopMenuOpen = false;
            }
        },
    }));
});
