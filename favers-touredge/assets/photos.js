(function () {
  'use strict';
  function init() {
    const start = document.getElementById('fte-add-photos');
    const stop = document.getElementById('fte-stop-photos');
    const status = document.getElementById('fte-photo-status');
    if (!start || !stop || !status || !window.faversTourEdgePhotos) return;
    let stopped = false;
    stop.addEventListener('click', function () {
      stopped = true;
      stop.disabled = true;
      status.textContent = 'Stopping after the current batch. Saved photos will be kept.';
    });
    start.addEventListener('click', async function () {
      stopped = false;
      let total = 0;
      start.disabled = true;
      stop.disabled = false;
      status.textContent = 'Finding products without photos…';
      try {
        while (!stopped) {
          const response = await fetch(window.faversTourEdgePhotos.url, {
            method: 'POST', credentials: 'same-origin',
            body: new URLSearchParams({action: 'favers_touredge_photos', nonce: window.faversTourEdgePhotos.nonce})
          });
          const payload = await response.json();
          if (!response.ok || !payload.success) throw new Error(payload.data?.message || 'The server rejected the request. Refresh this page and try again.');
          const batch = payload.data;
          total += batch.updated;
          if (batch.errors.length) throw new Error(batch.errors.join(' '));
          status.textContent = total + ' product photos added this run. ' + batch.remaining + ' remaining.';
          if (!batch.remaining) {
            status.textContent = total ? 'Finished. ' + total + ' product photos added this run. Existing photos were preserved. Refresh Products to see the images.' : 'No products with supported image URLs need photos. If products still lack images, import an image_url column or add photos manually in Products.';
            return;
          }
          if (!batch.updated) throw new Error('No progress in the last batch. Refresh this page and retry.');
        }
        status.textContent = 'Stopped. ' + total + ' product photos added this run. Click Add missing product photos to resume.';
      } catch (error) {
        status.textContent = 'Stopped after ' + total + ' photos added this run. ' + error.message + ' Saved photos are kept; you can retry.';
      } finally {
        start.disabled = false;
        stop.disabled = true;
      }
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
