# מודול הקצאת חשבוניות - רשות המסים

מערכת זו מיועדת ליירוט תהליך יצירת חשבוניות, בדיקת חובת הקצאה מול רשות המסים, ועדכון מערכת הנהלת החשבונות.

## משימה 1 — תרשים תהליך (Flowchart)

```mermaid
graph TD
    Start[יצירת חשבונית ב-CRM] --> CheckB2B{האם ללקוח יש ח.פ.?}
    CheckB2B -- לא --> NormalFlow[המשך תהליך רגיל ללא הקצאה]
    CheckB2B -- כן --> CheckThreshold{האם הסכום גדול מסף ההקצאה לפי הקונפיגורציה?}
    CheckThreshold -- לא --> NormalFlow
    CheckThreshold -- כן --> AuthCheck{האם יש טוקן בתוקף בזיכרון?}
    
    AuthCheck -- לא --> GetToken[בקשת טוקן חדש משרת רשות המסים]
    GetToken --> SaveToken[שמירת טוקן במטמון]
    SaveToken --> ApiCall
    AuthCheck -- כן --> ApiCall[שליחת בקשת הקצאה - שירות אישור]
    
    ApiCall --> HttpResponse{מה תשובת השרת?}
    
    HttpResponse -- שגיאת רשת או Timeout --> RetryCheck{האם היו פחות מ-3 ניסיונות?}
    RetryCheck -- כן --> ApiCall
    RetryCheck -- לא --> NetworkErrorLog[רישום לוג ועצירת התהליך]
    
    HttpResponse -- 460 / 461 / 462 סירוב --> DeniedLog[רישום שגיאה בלוג]
    DeniedLog --> BlockInvoice[חסימת שליחה או סימון לבדיקה]
    
    HttpResponse -- סטטוס 200 מאושר --> SuccessExtract[חילוץ מספר הקצאה]
    SuccessExtract --> FormatDigits[גזירת 9 הספרות הימניות להדפסה]
    FormatDigits --> SaveDB[שמירת המספר המלא במסד נתונים]
    SaveDB --> UpdatePDF[הדפסת 9 הספרות על קובץ החשבונית]
    UpdatePDF --> PriorityLine[הוספת 9 הספרות לשדה אסמכתא 2]
    PriorityLine --> SendPriority[שליחת קובץ למערכת הנהלת חשבונות]