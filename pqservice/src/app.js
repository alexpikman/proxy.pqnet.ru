import express from 'express';
import cookieParser from 'cookie-parser';

import authRoutes from './routes/auth.routes.js';
import userRoutes from './routes/user.routes.js';
import billingRoutes from './routes/billing.routes.js';

import { rateLimiter } from './middleware/rateLimit.middleware.js';
import { errorHandler } from './middleware/error.middleware.js';

import adminRoutes from './routes/admin.routes.js';

const app = express();

app.use('/admin', adminRoutes);
app.use(express.json());
app.use(cookieParser());

// глобальный rate limit
app.use(rateLimiter);

// маршруты
app.use('/login', authRoutes);
app.use('/user', userRoutes);
app.use('/billing', billingRoutes);

// обработка ошибок
app.use(errorHandler);

export default app;
