(function (Drupal, once) {
    Drupal.behaviors.signageDashboardConfirmRemove = {
        attach(context) {
            once('signage-dashboard-confirm-remove', '.js-confirm-remove-message', context).forEach((element) => {
                element.addEventListener('click', (event) => {
                    const message = element.getAttribute('data-confirm-message') || 'Er du sikker?';
                    if (!window.confirm(message)) {
                        event.preventDefault();
                    }
                });
            });
        }
    };
})(Drupal, once);