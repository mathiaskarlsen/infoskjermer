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

        if (!items.length) {
          viewport.textContent = 'No active slides.';
          return;
        }

        let currentIndex = 0;
        let timerId = null;

        const clearViewport = () => {
          while (viewport.firstChild) {
            viewport.removeChild(viewport.firstChild);
          }
        };

        const getDurationMs = (item) => {
          const seconds = Number(item.duration) || 10;
          return Math.max(seconds, 1) * 1000;
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

        showNext();

        container.addEventListener('signagePlayer:destroy', () => {
          if (timerId) {
            window.clearTimeout(timerId);
          }
        });
      });
    }
  };
})(Drupal, once);