DELETE s1 FROM settings s1
INNER JOIN settings s2 
ON s1.namespace = s2.namespace 
AND s1.key = s2.key 
AND (s1.user_id = s2.user_id OR (s1.user_id IS NULL AND s2.user_id IS NULL))
WHERE s1.id < s2.id;
