import express from 'express';
import cookieParser from 'cookie-parser';
import path from 'path';
import { fileURLToPath } from 'url';
import dotenv from 'dotenv';
dotenv.config();
import authRoutes from './routes/auth.routes.js';
import userRoutes from './routes/user.routes.js';
import billingRoutes from './routes/billing.routes.js';
import adminRoutes from './routes/admin.routes.js';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const app = express();
app.use(express.json());
app.use(cookieParser());
app.use(express.static(path.join(__dirname, '../frontend/public')));

const router = express.Router();
authRoutes(router);
userRoutes(router);
billingRoutes(router);
adminRoutes(router);
app.use('/', router);

app.listen(process.env.PORT || 3000, () => console.log(`Server on port ${process.env.PORT || 3000}`));
