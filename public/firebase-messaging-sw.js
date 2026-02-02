// Scripts for firebase and firebase-messaging
importScripts('https://www.gstatic.com/firebasejs/10.7.1/firebase-app-compat.js');
importScripts('https://www.gstatic.com/firebasejs/10.7.1/firebase-messaging-compat.js');

// Initialize the Firebase app in the service worker by passing in the messagingSenderId.
firebase.initializeApp({
  apiKey: "AIzaSyAEcO9dgmtSKCYupxlFyaPoCI_4ktzk6eQ",
  authDomain: "dialer-phonify-app.firebaseapp.com",
  projectId: "dialer-phonify-app",
  storageBucket: "dialer-phonify-app.firebasestorage.app",
  messagingSenderId: "984571065396",
  appId: "1:984571065396:web:835c27fbe50614499cc661",
  measurementId: "G-C6NKCY623V"
});

// Retrieve an instance of Firebase Messaging so that it can handle background messages.
const messaging = firebase.messaging();

messaging.onBackgroundMessage((payload) => {
  console.log('[firebase-messaging-sw.js] Received background message ', payload);
  // Customize notification here
  const notificationTitle = payload.notification.title;
  const notificationOptions = {
    body: payload.notification.body,
    icon: '/firebase-logo.png'
  };

  self.registration.showNotification(notificationTitle,
    notificationOptions);
});
