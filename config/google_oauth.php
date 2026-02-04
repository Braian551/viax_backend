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
    'auth_uri' => 'https://accounts.google.com/o/oauth2/auth',
    'token_uri' => 'https://oauth2.googleapis.com/token',
    'cert_url' => 'https://www.googleapis.com/oauth2/v1/certs',
];
