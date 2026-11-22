<?php
/**
 * Configuración de Google OAuth
 * 
 * Este archivo contiene las credenciales de Google OAuth
 * Proyecto Firebase: viax-81a5e
 * NO DEBE SER ACCESIBLE PÚBLICAMENTE
 */

return [
    // Web Client (para verificar tokens desde el backend)
    'web' => [
        'client_id' => '879318355876-ii7g05sqsun2fijeqe9mik186a3fbisb.apps.googleusercontent.com',
        // No se requiere client_secret para verificación de id_token
    ],
    
    // Android Client (para la app móvil)
    'mobile' => [
        'client_id' => '879318355876-e3nakm38is93umdd2d1l7kph07ucioll.apps.googleusercontent.com',
        'project_id' => 'viax-81a5e',
    ],
    
    // URLs de OAuth
    // Android OAuth clients for the signing certificates of com.viax.app.
    'android_client_ids' => [
        '879318355876-3i0kutlui3r7oj9kil1o4jooa9dfhpf3.apps.googleusercontent.com',
        '879318355876-d1kb045o064fn91pj5l7jhet64kp0stg.apps.googleusercontent.com',
        '879318355876-h1388rjiv72j80efn4gheld0goqmvn2g.apps.googleusercontent.com',
        '879318355876-un9cifoga3vr277j0h4rn00e7rgshrg7.apps.googleusercontent.com',
    ],
    'auth_uri' => 'https://accounts.google.com/o/oauth2/auth',
    'token_uri' => 'https://oauth2.googleapis.com/token',
    'cert_url' => 'https://www.googleapis.com/oauth2/v1/certs',
];
