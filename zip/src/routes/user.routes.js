import express from "express";

const router = express.Router();

// MOCK USER (заменится позже реальной логикой)
router.get("/user-data", (req, res) => {
  res.json({
    uuid: "test-uuid",
    login: "test",
    coins: 0,
    status: "active",
    expire: Date.now()
  });
});

export default router;
