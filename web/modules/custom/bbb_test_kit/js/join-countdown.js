/**
 * @file
 * Counts down to the room's start time, then asks the server for the join
 * button over Drupal AJAX (bbb_test_kit.join_button).
 *
 * The script never shows a button itself: the server decides and either
 * returns the join form or a fresh countdown.
 */
((Drupal, once) => {
  const pad = (n) => String(n).padStart(2, '0');

  function remaining(seconds) {
    const d = Math.floor(seconds / 86400);
    const h = Math.floor((seconds % 86400) / 3600);
    const m = Math.floor((seconds % 3600) / 60);
    const s = seconds % 60;
    const time = `${pad(h)}:${pad(m)}:${pad(s)}`;
    return d > 0 ? `${Drupal.formatPlural(d, '1 day', '@count days')} ${time}` : time;
  }

  Drupal.behaviors.bbbTestKitJoinCountdown = {
    attach(context) {
      once('bbb-test-kit-countdown', '[data-bbb-test-kit-url]', context).forEach((el) => {
        // AJAX responses carry fresh server seconds; the (maybe cached) page
        // carries the absolute start time.
        const target = el.dataset.bbbTestKitSeconds !== undefined
          ? Date.now() + Math.max(1, parseInt(el.dataset.bbbTestKitSeconds, 10)) * 1000
          : parseInt(el.dataset.bbbTestKitStart, 10) * 1000;
        if (Number.isNaN(target)) {
          return;
        }

        let timer;
        const tick = () => {
          const left = Math.ceil((target - Date.now()) / 1000);
          if (left > 0) {
            el.textContent = Drupal.t('The meeting starts in @time.', { '@time': remaining(left) });
            return;
          }
          clearInterval(timer);
          el.textContent = Drupal.t('The meeting is starting…');
          Drupal.ajax({ url: el.dataset.bbbTestKitUrl, progress: { type: 'none' } }).execute();
        };
        timer = setInterval(tick, 1000);
        tick();
      });
    },
  };
})(Drupal, once);
