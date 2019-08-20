<?php

namespace humhub\modules\fcmPush;


use humhub\modules\fcmPush\components\NotificationTargetProvider;
use humhub\modules\fcmPush\models\ConfigureForm;
use humhub\modules\notification\targets\MobileTargetProvider;
use humhub\modules\web\pwa\controllers\ManifestController;
use humhub\modules\web\pwa\controllers\ServiceWorkerController;
use Yii;
use yii\helpers\Url;

class Events
{

    public static function onBeforeRequest($event)
    {
        if (ConfigureForm::getInstance()->isActive()) {
            Yii::$container->set(MobileTargetProvider::class, NotificationTargetProvider::class);
        }
    }

    public static function onManifestControllerInit($event)
    {
        /** @var ManifestController $controller */
        $controller = $event->sender;
        $controller->manifest['gcm_sender_id'] = (string)103953800507;
    }

    public static function onServiceWorkerControllerInit($event)
    {
        /** @var ServiceWorkerController $controller */
        $controller = $event->sender;

        $configureForm = ConfigureForm::getInstance();
        $senderId = $configureForm->senderId;
        
        // Service Worker Addons
        $controller->additionalJs .= <<<JS

            // Give the service worker access to Firebase Messaging.
            importScripts('https://www.gstatic.com/firebasejs/6.3.3/firebase-app.js');
            importScripts('https://www.gstatic.com/firebasejs/6.3.3/firebase-messaging.js');
            
            firebase.initializeApp({messagingSenderId: "{$senderId}"});
            
            const messaging = firebase.messaging();
            messaging.setBackgroundMessageHandler(function(payload) {
              const notificationTitle = payload.data.title;
              const notificationOptions = {
                body: payload.data.body,
                icon: payload.data.icon
              };
              return self.registration.showNotification(notificationTitle, notificationOptions);
            });

JS;
    }

    public static function onLayoutaddonInit($event)
    {
        $view = Yii::$app->view;
        $view->registerJsFile('https://www.gstatic.com/firebasejs/6.3.3/firebase-app.js');
        $view->registerJsFile('https://www.gstatic.com/firebasejs/6.3.3/firebase-messaging.js');

        $tokenUpdateUrl = Url::to(['/fcm-push/token/update']);

        $configureForm = ConfigureForm::getInstance();
        $senderId = $configureForm->senderId;

        $script = <<<JS
            firebase.initializeApp({messagingSenderId: "{$senderId}"});
            
            const messaging = firebase.messaging();
            
            function afterServiceWorkerRegistration(registration) {
                messaging.useServiceWorker(registration);
                    
                // Request for permission
                messaging.requestPermission().then(function() {
                  console.log('Notification permission granted.');

                  messaging.getToken().then(function(currentToken) {
                    if (currentToken) {
                      console.log('Token: ' + currentToken);
                      sendTokenToServer(currentToken);
                    } else {
                      console.log('No Instance ID token available. Request permission to generate one.');
                      setTokenSentToServer(false);
                    }
                  })
                  .catch(function(err) {
                    console.log('An error occurred while retrieving token. ', err);
                    setTokenSentToServer(false);
                  });
                })
                .catch(function(err) {
                  // e.g. Igonito Mode  
                  //console.log('Unable to get permission to notify.', err);
                });
            }
            
            // Handle incoming messages
            messaging.onMessage(function(payload) {
              console.log("FCM Notification received: ", payload);
            });
            
            // Callback fired if Instance ID token is updated.
            messaging.onTokenRefresh(function() {
              messaging.getToken().then(function(refreshedToken) {
                console.log('Token refreshed.');
                setTokenSentToServer(false);
                sendTokenToServer(refreshedToken);
              }).catch(function(err) {
                console.log('Unable to retrieve refreshed token ', err);
              });
            });
            
            // Send the Instance ID token your application server, so that it can:
            // - send messages back to this app
            // - subscribe/unsubscribe the token from topics
            function sendTokenToServer(currentToken) {
              if (!isTokenSentToServer()) {
                console.log('Sending token to server...');
                $.ajax({
                  method: "POST",
                  url: "{$tokenUpdateUrl}",
                  data: { token: currentToken }
                });
                setTokenSentToServer(true);
              } else {
                console.log('Token already sent to server so won\'t send it again unless it changes');
              }
            }
            
            function isTokenSentToServer() {
              return window.localStorage.getItem('FcmTokenSentToServer') == 1;
            }
            
            function setTokenSentToServer(sent) {
              window.localStorage.setItem('FcmTokenSentToServer', sent ? 1 : 0);
            }
JS;
        $view->registerJs($script);

    }

}