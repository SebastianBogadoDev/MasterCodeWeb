/* =====================================================
   PAYMENT STORE — Persistencia ligera en JSON
   ─────────────────────────────────────────────────
   Escribe cada pago/evento en server/data/payments.json.
   Suficiente para una agencia con bajo volumen.
   Para escalar: reemplazar con PostgreSQL o MongoDB.
===================================================== */

import { readFile, writeFile, mkdir } from "fs/promises";
import { join, dirname }              from "path";
import { fileURLToPath }              from "url";

const __dirname = dirname(fileURLToPath(import.meta.url));
const DATA_DIR  = join(__dirname, "../data");
const FILE      = join(DATA_DIR, "payments.json");

/**
 * Añade un registro al store.
 * Nunca lanza excepciones — un fallo de disco no debe
 * interrumpir la respuesta al webhook de Stripe.
 *
 * @param {object} record  Datos a guardar
 */
export async function savePayment(record) {
  try {
    await mkdir(DATA_DIR, { recursive: true });

    let list = [];
    try {
      const raw = await readFile(FILE, "utf-8");
      list = JSON.parse(raw);
      if (!Array.isArray(list)) list = [];
    } catch {
      // El archivo no existe todavía — empezamos vacío
    }

    list.push({ ...record, _savedAt: new Date().toISOString() });

    await writeFile(FILE, JSON.stringify(list, null, 2), "utf-8");

    console.log(`[MCW store] ✅ Registro guardado: ${record.type} · ${record.email || "sin email"}`);

  } catch (err) {
    console.error("[MCW store] ❌ No se pudo guardar el pago:", err.message);
  }
}
