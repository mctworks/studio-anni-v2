-- 0004: insurance schedule (cents). Carried over from V1 (dated 4/15/2026); USPS now lists insurance as
-- "starting at $2.80", so check every tier against the current USPS price list and update this row.
-- Priority Mail already includes $100 of insurance (included_cents). Above the last tier: +over_step_fee_cents per over_step_cents or fraction.

INSERT IGNORE INTO settings (setting_key, setting_value) VALUES
    ('usps_insurance_schedule', '{"effective":"2026-04-15","included_cents":10000,"tiers":[[20000,270],[30000,460],[40000,650],[50000,840]],"over_step_cents":10000,"over_step_fee_cents":190}');