'use strict';

/**
 * Logger minimalista con niveles.
 */

const config = require('./config');

const LEVELS = { debug: 0, info: 1, warn: 2, error: 3 };
const currentLevel = LEVELS[config.logging.level] ?? LEVELS.info;

function formatTime() {
  return new Date().toISOString();
}

const logger = {
  debug(...args) {
    if (currentLevel <= LEVELS.debug) {
      console.log(`${formatTime()} [DEBUG]`, ...args);
    }
  },

  info(...args) {
    if (currentLevel <= LEVELS.info) {
      console.log(`${formatTime()} [INFO]`, ...args);
    }
  },

  warn(...args) {
    if (currentLevel <= LEVELS.warn) {
      console.warn(`${formatTime()} [WARN]`, ...args);
    }
  },

  error(...args) {
    if (currentLevel <= LEVELS.error) {
      console.error(`${formatTime()} [ERROR]`, ...args);
    }
  },
};

module.exports = { logger };
