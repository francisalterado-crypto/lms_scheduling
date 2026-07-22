/* Student offline shell — cache reader page + assets for offline faculty-item reading. */
var CACHE_NAME = 'class-student-offline-v2';
var PRECACHE = [
  './student_offline.php',
  './assets/js/student_offline.js',
  './assets/css/style.css',
  './assets/wpu-logo.png',
  'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css',
  'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js',
  'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css',
];

self.addEventListener('install', function (event) {
  event.waitUntil(
    caches
      .open(CACHE_NAME)
      .then(function (cache) {
        return Promise.all(
          PRECACHE.map(function (url) {
            return cache.add(url).catch(function () {
              return null;
            });
          })
        );
      })
      .then(function () {
        return self.skipWaiting();
      })
  );
});

self.addEventListener('activate', function (event) {
  event.waitUntil(
    caches
      .keys()
      .then(function (keys) {
        return Promise.all(
          keys.map(function (key) {
            if (key !== CACHE_NAME) {
              return caches.delete(key);
            }
            return null;
          })
        );
      })
      .then(function () {
        return self.clients.claim();
      })
  );
});

self.addEventListener('fetch', function (event) {
  var req = event.request;
  if (req.method !== 'GET') {
    return;
  }

  var url = new URL(req.url);
  var path = url.pathname || '';
  var isOfflinePage = /\/student_offline\.php$/i.test(path);
  var isOfflineAsset =
    /\/assets\/js\/student_offline\.js$/i.test(path) ||
    /\/assets\/css\/style\.css$/i.test(path) ||
    /\/assets\/wpu-logo\.png$/i.test(path) ||
    /bootstrap@5\.3\.3\//i.test(url.href) ||
    /font-awesome\/6\.5\.1\//i.test(url.href);

  if (!isOfflinePage && !isOfflineAsset) {
    return;
  }

  event.respondWith(
    fetch(req)
      .then(function (res) {
        if (res && res.ok) {
          var copy = res.clone();
          caches.open(CACHE_NAME).then(function (cache) {
            cache.put(req, copy);
          });
        }
        return res;
      })
      .catch(function () {
        return caches.match(req).then(function (cached) {
          if (cached) {
            return cached;
          }
          if (isOfflinePage) {
            return caches.match('./student_offline.php');
          }
          return Response.error();
        });
      })
  );
});
