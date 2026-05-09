import express from "express";
import { sendEmail } from "../services/email.js";

const router = express.Router();

router.post("/", async (req, res) => {

  try {

    const {
      nombre = "Cliente web",
      email = "no-email@web.com",
      plan = "No especificado",
      precio = "0",
      origen = "web"
    } = req.body;

    // ========================
    // LOG PROFESIONAL
    // ========================
    console.log("NUEVO LEAD:");
    console.log({
      nombre,
      email,
      plan,
      precio,
      origen,
      fecha: new Date().toISOString()
    });

    // ========================
    // EMAIL (MEJORADO)
    // ========================
    await sendEmail({
      nombre,
      email,
      plan,
      precio,
      origen
    });

    // ========================
    // RESPUESTA
    // ========================
    res.json({
      success: true,
      message: "Lead recibido correctamente"
    });

  } catch (error) {

    console.error("❌ ERROR LEAD:", error);

    res.status(500).json({
      success: false,
      error: "Error procesando lead"
    });

  }

});

export default router;