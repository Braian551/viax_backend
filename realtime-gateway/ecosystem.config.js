// Configuración PM2 para el gateway WebSocket de tiempo real.
// Uso: pm2 start ecosystem.config.js
module.exports = {
  apps: [
    {
      name: 'viax-ws-gateway',
      script: 'src/server.js',
      cwd: '/var/www/viax/backend/realtime-gateway',
      instances: 1,
      exec_mode: 'fork',
      max_memory_restart: '256M',
      env: {
        NODE_ENV: 'production',
        WS_PORT: 9100,
        REDIS_HOST: '127.0.0.1',
        REDIS_PORT: 6379,
        LOG_LEVEL: 'info',
      },
      // Reinicio automático con backoff exponencial
      restart_delay: 2000,
      max_restarts: 20,
      min_uptime: '10s',
      // Logs
      error_file: '/var/log/viax/ws-gateway-error.log',
      out_file: '/var/log/viax/ws-gateway-out.log',
      merge_logs: true,
      log_date_format: 'YYYY-MM-DD HH:mm:ss Z',
    },
  ],
};
