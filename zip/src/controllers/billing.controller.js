import * as billingService from '../services/billing.service.js';

export const buyDays = async (req, res, next) => {
  try {
    const user = req.user;
    const { days } = req.body;
    const key = req.idempotencyKey;

    const result = await billingService.buyDays(user, days, key);

    res.json(result);

  } catch (err) {
    next(err);
  }
};