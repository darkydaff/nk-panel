-- Add last_payment_date and last_payment_amount to ext_clients table
ALTER TABLE ext_clients
ADD COLUMN last_payment_date DATE NULL,
ADD COLUMN last_payment_amount DECIMAL(10,2) NULL;
