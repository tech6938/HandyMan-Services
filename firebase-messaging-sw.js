importScripts('https://www.gstatic.com/firebasejs/8.3.2/firebase-app.js');
importScripts('https://www.gstatic.com/firebasejs/8.3.2/firebase-messaging.js');
firebase.initializeApp({
    apiKey: "",
    authDomain: "",
    projectId: "",
    storageBucket: "",
    messagingSenderId: "",
    appId: "",
    measurementId: ""
});
const messaging = firebase.messaging();
messaging.setBackgroundMessageHandler(function (payload) {
    const data = payload.data || {};
    const notification = payload.notification || {};
    const image = data.image || notification.image || '';

    return self.registration.showNotification(data.title || notification.title || '', {
        body: data.body || notification.body || '',
        icon: data.icon || notification.icon || '',
        image: image,
        data: data
    });
});
