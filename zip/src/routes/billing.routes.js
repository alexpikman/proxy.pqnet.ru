import express from 'express';
import { authMiddleware } from '../middleware/auth.middleware.js';
import { idempotencyMiddleware } from '../middleware/idempotency.middleware.js';
import { buyDays } from '../controllers/billing.controller.js';

const router = express.Router();

router.post(
  '/buy-days',
  authMiddleware,
  idempotencyMiddleware,
  buyDays
);

export default router;