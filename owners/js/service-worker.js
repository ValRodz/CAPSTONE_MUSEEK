// Service Worker for Push Notifications

const CACHE_NAME = 'museek-cache-v1';
const urlsToCache = [
  '/',
  '/index.php',
  '/dashboard.php',
  '/bookings.php',
  '/schedule.php',
  '/payments.php',
  '/notifications.php',
  '/push-notifications.php',
  '/images/logo.png',
  '/images/badge.png'
];

// Install event - cache assets
self.addEventListener('install', event => {
  console.log('Service Worker installing...');
  self.skipWaiting();
  
  event.waitUntil(
    caches.open(CACHE_NAME)
      .then(cache => {
        console.log('Opened cache');
        return cache.addAll(urlsToCache);
      })
  );
});

// Activate event - clean up old caches
self.addEventListener('activate', event => {
  console.log('Service Worker activating...');
  
  event.waitUntil(
    caches.keys().then(cacheNames => {
      return Promise.all(
        cacheNames.filter(cacheName => {
          return cacheName !== CACHE_NAME;
        }).map(cacheName => {
          return caches.delete(cacheName);
        })
      );
    }).then(() => self.clients.claim())
  );
});

// Fetch event - serve from cache if available
self.addEventListener('fetch', event => {
  event.respondWith(
    caches.match(event.request)
      .then(response => {
        // Cache hit - return response
        if (response) {
          return response;
        }
        return fetch(event.request);
      })
  );
});

// Push event - handle incoming push notifications
self.addEventListener('push', event => {
  console.log('Push notification received:', event);
  
  if (!event.data) {
    console.log('No data received with push event');
    return;
  }
  
  try {
    const data = event.data.json();
    console.log('Push data:', data);
    
    const title = data.title || 'MuSeek Notification';
    const options = {
      body: data.body || 'You have a new notification',
      icon: data.icon || '/images/logo.png',
      badge: data.badge || '/images/badge.png',
      data: data.data || { url: '/' },
      actions: data.actions || [],
      tag: data.tag || 'default',
      renotify: data.renotify || false,
      requireInteraction: data.requireInteraction || false,
      silent: data.silent || false
    };
    
    event.waitUntil(
      self.registration.showNotification(title, options)
    );
  } catch (error) {
    console.error('Error processing push notification:', error);
  }
});

// Notification click event - handle notification clicks
self.addEventListener('notificationclick', event => {
  console.log('Notification clicked:', event);
  
  event.notification.close();
  
  // Handle notification action clicks
  if (event.action === 'request_payment' && event.notification.data && event.notification.data.bookingId) {
    const url = `/request_payment.php?booking_id=${event.notification.data.bookingId}`;
    event.waitUntil(openUrl(url));
    return;
  }
  
  // Default action - open the URL from notification data
  const url = event.notification.data && event.notification.data.url ? event.notification.data.url : '/';
  event.waitUntil(openUrl(url));
});

// Helper function to open URL in existing tab or new window
function openUrl(url) {
  return clients.matchAll({ type: 'window' }).then(windowClients => {
    // Check if there is already a window/tab open with the target URL
    for (let i = 0; i < windowClients.length; i++) {
      const client = windowClients[i];
      if (client.url === url && 'focus' in client) {
        return client.focus();
      }
    }
    
    // If no window/tab is open, open a new one
    if (clients.openWindow) {
      return clients.openWindow(url);
    }
  });
}