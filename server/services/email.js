import nodemailer from "nodemailer";

/* ========================
   Escapa HTML para evitar XSS en emails
======================== */

function esc(text) {
  if (typeof text !== "string") return String(text ?? "");
  return text
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;");
}

/* ========================
   Transporter — configurado desde variables de entorno
======================== */

function createTransporter() {
  return nodemailer.createTransport({
    service: "gmail",
    auth: {
      user: process.env.GMAIL_USER,
      pass: process.env.GMAIL_PASSWORD
    }
  });
}

export async function sendEmail({ nombre, email, plan, precio, origen }) {

  // Si no hay credenciales configuradas, loguear y salir sin lanzar error
  if (!process.env.GMAIL_USER || !process.env.GMAIL_PASSWORD) {
    console.warn("Email no enviado: GMAIL_USER o GMAIL_PASSWORD no configurados en .env");
    return;
  }

  const transporter = createTransporter();

  const safenombre = esc(nombre);
  const safeEmail  = esc(email);
  const safePlan   = esc(plan);
  const safePrecio = esc(precio);
  const safeOrigen = esc(origen);

  /* =====================================================
     1. EMAIL AL CLIENTE
  ===================================================== */

  await transporter.sendMail({
    from: `"MasterCodeWeb" <${process.env.GMAIL_USER}>`,
    to: safeEmail,
    subject: "Hemos recibido tu solicitud · MasterCodeWeb",
    html: `
      <!DOCTYPE html>
      <html lang="es">
      <body style="font-family:Inter,sans-serif;background:#f9fafb;padding:40px 20px;">
        <div style="max-width:560px;margin:0 auto;background:#ffffff;border-radius:12px;padding:32px;border:1px solid #e5e7eb;">
          <h2 style="color:#0d6cf2;margin-top:0;">Hola, ${safenombre} 👋</h2>
          <p style="color:#374151;">Gracias por confiar en <strong>MasterCodeWeb</strong>. Hemos recibido tu solicitud correctamente.</p>
          <table style="width:100%;border-collapse:collapse;margin:16px 0;">
            <tr style="background:#f3f4f6;">
              <td style="padding:8px 12px;color:#6b7280;font-size:0.875rem;">Plan</td>
              <td style="padding:8px 12px;color:#111827;font-weight:600;">${safePlan}</td>
            </tr>
            <tr>
              <td style="padding:8px 12px;color:#6b7280;font-size:0.875rem;">Precio</td>
              <td style="padding:8px 12px;color:#111827;font-weight:600;">${safePrecio}€</td>
            </tr>
          </table>
          <p style="color:#374151;">Te responderemos en <strong>menos de 24 horas</strong>. Si necesitas una respuesta urgente:</p>
          <a href="https://wa.me/34680762047"
             style="display:inline-block;padding:12px 24px;background:#25d366;color:#fff;border-radius:8px;text-decoration:none;font-weight:700;">
             Escribir por WhatsApp
          </a>
          <p style="margin-top:24px;color:#9ca3af;font-size:0.8rem;">
            MasterCodeWeb · Algarrobo, Málaga · España
          </p>
        </div>
      </body>
      </html>
    `
  });

  /* =====================================================
     2. NOTIFICACIÓN INTERNA
  ===================================================== */

  await transporter.sendMail({
    from: `"MasterCodeWeb" <${process.env.GMAIL_USER}>`,
    to: process.env.GMAIL_USER,
    subject: `Nuevo lead: ${safenombre} · ${safePlan}`,
    html: `
      <!DOCTYPE html>
      <html lang="es">
      <body style="font-family:Inter,sans-serif;background:#f9fafb;padding:40px 20px;">
        <div style="max-width:480px;margin:0 auto;background:#ffffff;border-radius:12px;padding:32px;border:1px solid #e5e7eb;">
          <h2 style="color:#0d6cf2;margin-top:0;">Nuevo lead recibido</h2>
          <table style="width:100%;border-collapse:collapse;">
            <tr style="background:#f3f4f6;">
              <td style="padding:8px 12px;color:#6b7280;">Nombre</td>
              <td style="padding:8px 12px;font-weight:600;">${safenombre}</td>
            </tr>
            <tr>
              <td style="padding:8px 12px;color:#6b7280;">Email</td>
              <td style="padding:8px 12px;">${safeEmail}</td>
            </tr>
            <tr style="background:#f3f4f6;">
              <td style="padding:8px 12px;color:#6b7280;">Plan</td>
              <td style="padding:8px 12px;font-weight:600;">${safePlan}</td>
            </tr>
            <tr>
              <td style="padding:8px 12px;color:#6b7280;">Precio</td>
              <td style="padding:8px 12px;">${safePrecio}€</td>
            </tr>
            <tr style="background:#f3f4f6;">
              <td style="padding:8px 12px;color:#6b7280;">Origen</td>
              <td style="padding:8px 12px;">${safeOrigen}</td>
            </tr>
          </table>
        </div>
      </body>
      </html>
    `
  });

}
