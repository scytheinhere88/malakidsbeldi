/*
  # Add promo_redemptions table

  ## Purpose
  Prevents race conditions when multiple concurrent requests attempt to redeem
  the same single-use promo code. The table records which users have redeemed
  which promo codes, enforced by a UNIQUE constraint.

  ## New Tables
  - `promo_redemptions`
    - `id` (bigserial, PK)
    - `promo_code_id` (int)
    - `user_id` (int)
    - `redeemed_at` (timestamptz)
    - UNIQUE (promo_code_id, user_id) — prevents double-redemption at DB level

  ## Notes
  - The UNIQUE constraint is the last line of defense against race conditions
    even if application-level locking fails
*/

CREATE TABLE IF NOT EXISTS promo_redemptions (
  id            BIGSERIAL PRIMARY KEY,
  promo_code_id INT NOT NULL,
  user_id       INT NOT NULL,
  redeemed_at   TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  CONSTRAINT uq_promo_user UNIQUE (promo_code_id, user_id)
);

CREATE INDEX IF NOT EXISTS idx_promo_redemptions_user  ON promo_redemptions (user_id);
CREATE INDEX IF NOT EXISTS idx_promo_redemptions_promo ON promo_redemptions (promo_code_id);

ALTER TABLE promo_redemptions ENABLE ROW LEVEL SECURITY;

CREATE POLICY "Service role can manage promo_redemptions"
  ON promo_redemptions
  FOR SELECT
  TO authenticated
  USING (auth.uid()::text = user_id::text);

CREATE POLICY "Service role can insert promo_redemptions"
  ON promo_redemptions
  FOR INSERT
  TO authenticated
  WITH CHECK (auth.uid()::text = user_id::text);
