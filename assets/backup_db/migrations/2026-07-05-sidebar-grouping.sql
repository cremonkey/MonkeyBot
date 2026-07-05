-- Clean sidebar grouping for the new features
UPDATE menu SET serial=10, header_text='CRM and Analytics' WHERE url='crm';
UPDATE menu SET serial=11, header_text='' WHERE url='analytics_hub';
UPDATE menu SET serial=40, header_text='More Channels' WHERE url='whatsapp_bot';
UPDATE menu SET serial=41, header_text='' WHERE url='telegram_bot';
UPDATE menu SET serial=42, header_text='' WHERE url='webchat';
UPDATE menu SET serial=43, header_text='Business Tools' WHERE url='appointment_booking';
UPDATE menu SET serial=44, header_text='' WHERE url='ai_content_writer';
