(function (Drupal, once) {
  Drupal.behaviors.signagePlayer = {
    attach(context) {
      once('signage-player', '.signage-player', context).forEach((container) => {
        let playback = {};

        try {
          playback = JSON.parse(container.dataset.playback || '{}');
        }
        catch (error) {
          console.error('Invalid playback data:', error);
          return;
        }

        const viewport = container.querySelector('#signage-player-slide') || container;
        const items = Array.isArray(playback.items) ? playback.items : [];
        const fallbackReason = playback?.status?.fallback_reason || null;

        let timerId = null;
        let reloadTimerId = null;
        let currentIndex = 0;

        const clearViewport = () => {
          while (viewport.firstChild) {
            viewport.removeChild(viewport.firstChild);
          }
        };

        const getDurationMs = (item) => {
          const seconds = Number(item.duration) || 10;
          return Math.max(seconds, 1) * 1000;
        };

        const getFallbackMessage = (reason) => {
          switch (reason) {
            case 'screen_not_found':
              return 'Screen not found.';
            case 'playlists_missing':
              return 'No playlists are connected to this screen.';
            case 'playlists_empty':
              return 'The connected playlists have no items.';
            case 'all_items_disabled':
              return 'All playlist items are disabled.';
            case 'all_items_outside_schedule':
              return 'No playlist items are scheduled right now.';
            case 'no_typed_items':
              return 'No playlist items have a slide selected.';
            case 'no_image_slides':
              return 'No image slides to display.';
            case 'no_valid_slides':
              return 'No playable slides were found.';
            default:
              return 'Nothing to display.';
          }
        };

        const renderFallback = (reason) => {
          clearViewport();

          const fallback = document.createElement('div');
          fallback.className = 'signage-player__fallback';

          const title = document.createElement('h2');
          title.className = 'signage-player__fallback-title';
          title.textContent = 'Player status';

          const message = document.createElement('p');
          message.className = 'signage-player__fallback-message';
          message.textContent = getFallbackMessage(reason);

          fallback.appendChild(title);
          fallback.appendChild(message);
          viewport.appendChild(fallback);
        };

        const renderItem = (item) => {
          clearViewport();

          const slideWrapper = document.createElement('div');
          slideWrapper.className = 'signage-player__slide';

          if (item.media_url) {
            const image = document.createElement('img');
            image.className = 'signage-player__image';
            image.src = item.media_url;
            image.alt = item.title || '';
            slideWrapper.appendChild(image);
          }

          if (item.title) {
            const title = document.createElement('h2');
            title.className = 'signage-player__title';
            title.textContent = item.title;
            slideWrapper.appendChild(title);
          }

          if (item.body) {
            const body = document.createElement('div');
            body.className = 'signage-player__body';
            body.textContent = item.body;
            slideWrapper.appendChild(body);
          }

          viewport.appendChild(slideWrapper);
        };

        const showNext = () => {
          const item = items[currentIndex];
          renderItem(item);

          currentIndex = (currentIndex + 1) % items.length;
          timerId = window.setTimeout(showNext, getDurationMs(item));
        };

        if (!items.length) {
          renderFallback(fallbackReason);
        }
        else {
          showNext();
        }

        reloadTimerId = window.setTimeout(() => {
          window.location.reload();
        }, 60000);

        container.addEventListener('signagePlayer:destroy', () => {
          if (timerId) {
            window.clearTimeout(timerId);
          }

          if (reloadTimerId) {
            window.clearTimeout(reloadTimerId);
          }
        });
      });
    }
  };
})(Drupal, once);