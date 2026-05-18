-- =========================================================
-- RADOG'S KENNEL PET HOTEL MANAGEMENT SYSTEM
-- SCRIPT A: DDL (TABLE & CONSTRAINT CREATION)
-- RDBMS: ORACLE
--date: 2026-04-10
-- =========================================================

-- 1. EMPLOYEE TABLE 
CREATE TABLE EMPLOYEE (
    Employee_ID NUMBER(10) PRIMARY KEY,
    Employee_Username VARCHAR2(50) UNIQUE NOT NULL,
    Password_Hash VARCHAR2(255) NOT NULL
);

-- 2. USER_GROUP TABLE 
CREATE TABLE USER_GROUP (
    User_Group_ID NUMBER(10) PRIMARY KEY,
    Group_Name VARCHAR2(50) UNIQUE NOT NULL
);

-- 3. USER_ACCOUNT TABLE
CREATE TABLE USER_ACCOUNT (
    Account_ID NUMBER(10) PRIMARY KEY,
    Username VARCHAR2(50) UNIQUE NOT NULL,
    Account_Status VARCHAR2(50) NOT NULL,
    Password_Hash VARCHAR2(255) NOT NULL,
    Employee_ID NUMBER(10) NOT NULL,
    User_Group_ID NUMBER(10) NOT NULL,
    CONSTRAINT fk_acc_employee FOREIGN KEY (Employee_ID) REFERENCES EMPLOYEE (Employee_ID),
    CONSTRAINT fk_acc_usrgrp FOREIGN KEY (User_Group_ID) REFERENCES USER_GROUP (User_Group_ID),
    CONSTRAINT chk_acc_status CHECK (Account_Status IN ('Active', 'Inactive'))
);

-- 4. USER_PRIVILEGE TABLE 
CREATE TABLE USER_PRIVILEGE (
    Privilege_ID NUMBER(10) PRIMARY KEY,
    Privilege_Name VARCHAR2(50) NOT NULL,
    User_Group_ID NUMBER(10) NOT NULL,
    CONSTRAINT fk_priv_usrgrp FOREIGN KEY (User_Group_ID) REFERENCES USER_GROUP (User_Group_ID)
);

-- 5. OWNER TABLE
CREATE TABLE OWNER (
    Owner_ID NUMBER(10) PRIMARY KEY,
    First_Name VARCHAR2(100) NOT NULL,
    Last_Name VARCHAR2(100) NOT NULL,
    Contact_Number VARCHAR2(20) UNIQUE NOT NULL,
    Status VARCHAR2(20) DEFAULT 'Active' NOT NULL,
    CONSTRAINT chk_owner_status CHECK (Status IN ('Active', 'Inactive'))
);

-- 6. PET_CATEGORY TABLE
CREATE TABLE PET_CATEGORY (
    Category_ID NUMBER(10) PRIMARY KEY,
    Category_Name VARCHAR2(50) UNIQUE NOT NULL,
    Species_Notes VARCHAR2(255)
);

-- 7. TIER TABLE 
CREATE TABLE TIER (
    Tier_ID NUMBER(10) PRIMARY KEY,
    Tier_Name VARCHAR2(20) UNIQUE NOT NULL,
    Tier_Description VARCHAR2(255),
    Weight_Min NUMBER(10, 2) NOT NULL,
    Weight_Max NUMBER(10, 2) NOT NULL,
    Daily_Rate NUMBER(10,2) NOT NULL,
    CONSTRAINT chk_tier_name CHECK (Tier_Name IN ('Small', 'Medium', 'Large', 'Giant')),
    CONSTRAINT chk_tier_w_min CHECK (Weight_Min >= 0.00),
    CONSTRAINT chk_tier_w_max CHECK (Weight_Max > Weight_Min),
    CONSTRAINT chk_acc_rate CHECK (Daily_Rate >= 0.00)
);

-- 8. PET TABLE
CREATE TABLE PET (
    Pet_ID NUMBER(10) PRIMARY KEY,
    Pet_Name VARCHAR2(50) NOT NULL,
    Sex VARCHAR2(10),
    Weight NUMBER(5,2),
    Feeding_Time VARCHAR2(20),
    Feeding_Portion VARCHAR2(50),
    Behavioral_Notes VARCHAR2(255),
    Status VARCHAR2(20) DEFAULT 'Active' NOT NULL,
    Owner_ID NUMBER(10) NOT NULL,
    Category_ID NUMBER(10) NOT NULL,
    Tier_ID NUMBER(10) NOT NULL,
    CONSTRAINT fk_pet_owner FOREIGN KEY (Owner_ID) REFERENCES OWNER (Owner_ID),
    CONSTRAINT fk_pet_category FOREIGN KEY (Category_ID) REFERENCES PET_CATEGORY (Category_ID),
    CONSTRAINT fk_pet_tier FOREIGN KEY (Tier_ID) REFERENCES TIER (Tier_ID),
    CONSTRAINT chk_pet_sex CHECK (Sex IN ('Male', 'Female')),
    CONSTRAINT chk_pet_weight CHECK (Weight > 0.00),
    CONSTRAINT chk_pet_status CHECK (Status IN ('Active', 'Inactive'))
);

-- 9. PET_DOCUMENT TABLE
CREATE TABLE PET_DOCUMENT (
    Doc_ID NUMBER(10) PRIMARY KEY,
    Document_Type VARCHAR2(50),
    Filepath VARCHAR2(255) UNIQUE NOT NULL,
    Upload_Date DATE DEFAULT SYSDATE,
    Pet_ID NUMBER(10) NOT NULL,
    CONSTRAINT fk_doc_pet FOREIGN KEY (Pet_ID) REFERENCES PET (Pet_ID)
);

-- 10. DOCUMENT_DETAILS TABLE
CREATE TABLE DOCUMENT_DETAILS (
    Pet_ID NUMBER(10) NOT NULL,
    Doc_ID NUMBER(10) NOT NULL,
    Verification_Status VARCHAR2(20) NOT NULL,
    Date_Verified DATE DEFAULT SYSDATE NOT NULL,
    CONSTRAINT pk_doc_details PRIMARY KEY (Pet_ID, Doc_ID),
    CONSTRAINT fk_det_pet FOREIGN KEY (Pet_ID) REFERENCES PET (Pet_ID),
    CONSTRAINT fk_det_doc FOREIGN KEY (Doc_ID) REFERENCES PET_DOCUMENT (Doc_ID),
    CONSTRAINT chk_doc_status CHECK (Verification_Status IN ('Approved', 'Pending', 'Rejected'))
);

-- 11. ACCOMMODATION TABLE
CREATE TABLE ACCOMMODATION (
    Accommodation_ID NUMBER(10) PRIMARY KEY,
    Unit_Name VARCHAR2(50) UNIQUE NOT NULL,
    Accommodation_Type VARCHAR2(50) NOT NULL,
    Occupancy_Status VARCHAR2(20) NOT NULL,
    Tier_ID NUMBER(10) NOT NULL,
    CONSTRAINT fk_acc_tier FOREIGN KEY (Tier_ID) REFERENCES TIER(Tier_ID),
    CONSTRAINT chk_occ_status CHECK (Occupancy_Status IN ('Available', 'Booked', 'Under Maintenance'))
);


-- 12. SERVICE TABLE
CREATE TABLE SERVICE (
    Service_ID NUMBER(10) PRIMARY KEY,
    Service_Name VARCHAR2(100) UNIQUE NOT NULL,
    Service_Description VARCHAR2(255),
    Price NUMBER(10,2) NOT NULL,
    CONSTRAINT chk_svc_price CHECK (Price >= 0.00)
);

-- 13. BOOKING TABLE
CREATE TABLE BOOKING (
    Booking_ID NUMBER (10) PRIMARY KEY,
    Booking_Status VARCHAR2(20) NOT NULL,
    Check_In_Date DATE NOT NULL,
    Check_Out_Date DATE NOT NULL,
    Consent_Form_Signed VARCHAR2(5),
    NexGard_Verified VARCHAR2(5),
    Ocular_Exam_Passed VARCHAR2(5),
    Vetcard_Verified VARCHAR2(5),
    Special_Instructions VARCHAR2(255),
    Total_Amount NUMBER(10,2) NOT NULL,
    Payment_Method VARCHAR2(20) NOT NULL,
    Date_Of_Payment DATE DEFAULT SYSDATE NOT NULL,
    Owner_ID NUMBER (10) NOT NULL,
    Pet_ID NUMBER (10) NOT NULL,
    Employee_ID NUMBER (10) NOT NULL,
    Accommodation_ID NUMBER (10) NOT NULL,
    CONSTRAINT fk_bk_owner FOREIGN KEY (Owner_ID) REFERENCES OWNER (Owner_ID),
    CONSTRAINT fk_bk_pet FOREIGN KEY (Pet_ID) REFERENCES PET(Pet_ID),
    CONSTRAINT fk_bk_employee FOREIGN KEY (Employee_ID) REFERENCES EMPLOYEE (Employee_ID),
    CONSTRAINT fk_bk_accommodation FOREIGN KEY (Accommodation_ID) REFERENCES ACCOMMODATION (Accommodation_ID),
    CONSTRAINT chk_bk_status CHECK (Booking_Status IN ('Confirmed', 'Pending', 'Cancelled', 'Completed')),
    CONSTRAINT chk_bk_dates CHECK (Check_Out_Date > Check_In_Date),
    CONSTRAINT chk_bk_total CHECK (Total_Amount >= 0.00),
    CONSTRAINT chk_bk_paymethod CHECK (Payment_Method IN ('Cash', 'GCash', 'Bank')),
    CONSTRAINT chk_consent CHECK (Consent_Form_Signed IN ('Yes', 'No')),
    CONSTRAINT chk_nexgard CHECK (NexGard_Verified IN ('Yes', 'No')),
    CONSTRAINT chk_ocular CHECK (Ocular_Exam_Passed IN ('Yes', 'No')),
    CONSTRAINT chk_vetcard CHECK (Vetcard_Verified IN ('Yes', 'No'))
);

-- 14. SERVICE_DETAILS TABLE
CREATE TABLE SERVICE_DETAILS (
    Booking_ID NUMBER(10) NOT NULL,
    Service_ID NUMBER(10) NOT NULL,
    Quantity NUMBER(5) NOT NULL,
    Service_Charge NUMBER(10,2) NOT NULL,
    CONSTRAINT pk_service_details PRIMARY KEY (Booking_ID, Service_ID),
    CONSTRAINT fk_svcdet_bk FOREIGN KEY (Booking_ID) REFERENCES BOOKING (Booking_ID),
    CONSTRAINT fk_svcdet_svc FOREIGN KEY (Service_ID) REFERENCES SERVICE (Service_ID),
    CONSTRAINT chk_svc_qty CHECK (Quantity > 0),
    CONSTRAINT chk_svc_charge CHECK (Service_Charge >= 0.00)
);

COMMIT;

-- SQL Codes for Initial and/or Sample Table Records Insertion
-- =========================================================
-- RADOG'S KENNEL PET HOTEL MANAGEMENT SYSTEM
-- SCRIPT B: DML (SAMPLE RECORDS INSERTION)
-- RDBMS: ORACLE
-- =========================================================

-- 1. EMPLOYEE TABLE
INSERT INTO EMPLOYEE (Employee_ID, Employee_Username, Password_Hash) 
VALUES (1, 'gordon.admin', 'hashed_pw_abc123');
INSERT INTO EMPLOYEE (Employee_ID, Employee_Username, Password_Hash) 
VALUES (2, 'staff.achilles', 'hashed_pw_xyz789');

-- 2. USER_GROUP TABLE
INSERT INTO USER_GROUP (User_Group_ID, Group_Name) 
VALUES (1, 'Administrator');
INSERT INTO USER_GROUP (User_Group_ID, Group_Name) 
VALUES (2, 'Kennel Staff');

-- 3. USER_ACCOUNT TABLE
INSERT INTO USER_ACCOUNT (Account_ID, Username, Account_Status, Password_Hash, Employee_ID, User_Group_ID) 
VALUES (1, 'gordon_daluro', 'Active', 'hashed_pw_abc123', 1, 1);
INSERT INTO USER_ACCOUNT (Account_ID, Username, Account_Status, Password_Hash, Employee_ID, User_Group_ID) 
VALUES (2, 'achilles_staff', 'Active', 'hashed_pw_xyz789', 2, 2);

-- 4. USER_PRIVILEGE TABLE
INSERT INTO USER_PRIVILEGE (Privilege_ID, Privilege_Name, User_Group_ID) 
VALUES (1, 'FULL_CRUD_ACCESS', 1);
INSERT INTO USER_PRIVILEGE (Privilege_ID, Privilege_Name, User_Group_ID) 
VALUES (2, 'LIMITED_DATA_ENTRY', 2);




-- 5. OWNER TABLE (Updated with Status for Soft Delete)
INSERT INTO OWNER (Owner_ID, First_Name, Last_Name, Contact_Number, Status) 
VALUES (1, 'Ayen', 'Tapales', '09564195628', 'Active');
INSERT INTO OWNER (Owner_ID, First_Name, Last_Name, Contact_Number, Status) 
VALUES (2, 'Zarah', 'Clores', '09123456789', 'Active');
INSERT INTO OWNER (Owner_ID, First_Name, Last_Name, Contact_Number, Status) 
VALUES (3, 'Tet', 'Marpuri', '09987654321', 'Active');

-- 6. PET_CATEGORY TABLE
INSERT INTO PET_CATEGORY (Category_ID, Category_Name, Species_Notes) 
VALUES (1, 'Dog', 'All recognized dog breeds');
INSERT INTO PET_CATEGORY (Category_ID, Category_Name, Species_Notes) 
VALUES (2, 'Cat', 'All recognized cat breeds');

-- 7. TIER TABLE
INSERT INTO TIER (Tier_ID, Tier_Name, Tier_Description, Weight_Min, Weight_Max, Daily_Rate) 
VALUES (1, 'Small', '< 10 kg', 0.00, 10.99, 500.00);
INSERT INTO TIER (Tier_ID, Tier_Name, Tier_Description, Weight_Min, Weight_Max, Daily_Rate) 
VALUES (2, 'Medium', '11-26 kg', 11.00, 26.99, 750.00);
INSERT INTO TIER (Tier_ID, Tier_Name, Tier_Description, Weight_Min, Weight_Max, Daily_Rate) 
VALUES (3, 'Large', '27-45 kg', 27.00, 45.99, 1000.00);
INSERT INTO TIER (Tier_ID, Tier_Name, Tier_Description, Weight_Min, Weight_Max, Daily_Rate) 
VALUES (4, 'Giant', '> 45 kg', 46.00, 150.00, 1500.00);

-- 8. PET TABLE (Updated with Status for Soft Delete)
INSERT INTO PET (Pet_ID, Pet_Name, Sex, Weight, Feeding_Time, Feeding_Portion, Behavioral_Notes, Status, Owner_ID, Category_ID, Tier_ID) 
VALUES (1, 'Beauty', 'Female', 25.50, 'BID', '2 cups morning, 1 cup evening', 'Very good temperament', 'Active', 1, 1, 2);
INSERT INTO PET (Pet_ID, Pet_Name, Sex, Weight, Feeding_Time, Feeding_Portion, Behavioral_Notes, Status, Owner_ID, Category_ID, Tier_ID) 
VALUES (2, 'Luna', 'Female', 12.00, 'TID', '1 scoop', 'Playful with other dogs', 'Active', 1, 1, 2);
INSERT INTO PET (Pet_ID, Pet_Name, Sex, Weight, Feeding_Time, Feeding_Portion, Behavioral_Notes, Status, Owner_ID, Category_ID, Tier_ID) 
VALUES (3, 'Cailey', 'Female', 15.00, 'BID', '1.5 cups', 'Wary but warms up easily', 'Active', 2, 1, 2);
INSERT INTO PET (Pet_ID, Pet_Name, Sex, Weight, Feeding_Time, Feeding_Portion, Behavioral_Notes, Status, Owner_ID, Category_ID, Tier_ID) 
VALUES (4, 'Frieza', 'Male', 4.50, 'BID', '1 pouch', 'Deaf but enjoys life', 'Active', 3, 2, 1);





-- 9. PET_DOCUMENT TABLE
INSERT INTO PET_DOCUMENT (Doc_ID, Document_Type, Filepath, Upload_Date, Pet_ID) 
VALUES (1, 'Vet Card', '/uploads/vetcards/beauty_vc_01.pdf', TO_DATE('2026-04-10', 'YYYY-MM-DD'), 1);
INSERT INTO PET_DOCUMENT (Doc_ID, Document_Type, Filepath, Upload_Date, Pet_ID) 
VALUES (2, 'Consent Waiver', '/uploads/waivers/frieza_w_01.pdf', TO_DATE('2026-04-10', 'YYYY-MM-DD'), 4);

-- 10. DOCUMENT_DETAILS TABLE (New Junction Table)
INSERT INTO DOCUMENT_DETAILS (Pet_ID, Doc_ID, Verification_Status, Date_Verified)
VALUES (1, 1, 'Approved', TO_DATE('2026-04-10', 'YYYY-MM-DD'));
INSERT INTO DOCUMENT_DETAILS (Pet_ID, Doc_ID, Verification_Status, Date_Verified)
VALUES (4, 2, 'Approved', TO_DATE('2026-04-10', 'YYYY-MM-DD'));

-- 11. ACCOMMODATION TABLE
INSERT INTO ACCOMMODATION (Accommodation_ID, Unit_Name, Accommodation_Type, Occupancy_Status, Tier_ID) 
VALUES (1, 'CAGE-S1', 'Stainless Cage', 'Available', 1);
INSERT INTO ACCOMMODATION (Accommodation_ID, Unit_Name, Accommodation_Type, Occupancy_Status, Tier_ID) 
VALUES (2, 'ROOM-M1', 'Airconditioned Room', 'Available', 2);
INSERT INTO ACCOMMODATION (Accommodation_ID, Unit_Name, Accommodation_Type, Occupancy_Status, Tier_ID) 
VALUES (3, 'ROOM-L1', 'Airconditioned Room', 'Booked', 3);

-- 12. SERVICE TABLE
INSERT INTO SERVICE (Service_ID, Service_Name, Service_Description, Price) 
VALUES (1, 'NexGard Administration', 'Mandatory if tick/flea spotted', 675.00);
INSERT INTO SERVICE (Service_ID, Service_Name, Service_Description, Price) 
VALUES (2, 'Premium Bubble Bath', 'Exit bath using Madre de Cacao', 0.00);
INSERT INTO SERVICE (Service_ID, Service_Name, Service_Description, Price) 
VALUES (3, 'Supervised Play Solo', 'Free play inside or outside', 0.00);

-- 13. BOOKING TABLE (Merged with Payment details)
INSERT INTO BOOKING (Booking_ID, Booking_Status, Check_In_Date, Check_Out_Date, Consent_Form_Signed, NexGard_Verified, Ocular_Exam_Passed, Vetcard_Verified, Special_Instructions, Total_Amount, Payment_Method, Date_Of_Payment, Owner_ID, Pet_ID, Employee_ID, Accommodation_ID) 
VALUES (1, 'Confirmed', TO_DATE('2026-04-11', 'YYYY-MM-DD'), TO_DATE ('2026-04-21', 'YYYY-MM-DD'), 'Yes', 'Yes', 'Yes', 'Yes', 'Will bring own chicken meals', 8175.00, 'GCash', TO_DATE('2026-04-11', 'YYYY-MM-DD'), 1, 1, 1, 2);
INSERT INTO BOOKING (Booking_ID, Booking_Status, Check_In_Date, Check_Out_Date, Consent_Form_Signed, NexGard_Verified, Ocular_Exam_Passed, Vetcard_Verified, Special_Instructions, Total_Amount, Payment_Method, Date_Of_Payment, Owner_ID, Pet_ID, Employee_ID, Accommodation_ID) 
VALUES (2, 'Completed', TO_DATE('2025-10-30', 'YYYY-MM-DD'), TO_DATE ('2025-11-02', 'YYYY-MM-DD'), 'Yes', 'No', 'Yes', 'Yes', 'Afternoon check-in', 1500.00, 'Cash', TO_DATE('2025-11-02', 'YYYY-MM-DD'), 2, 3, 2, 1);

-- 14. SERVICE_DETAILS TABLE (Junction Table)
INSERT INTO SERVICE_DETAILS (Booking_ID, Service_ID, Quantity, Service_Charge) 
VALUES (1, 3, 3, 0.00);
INSERT INTO SERVICE_DETAILS (Booking_ID, Service_ID, Quantity, Service_Charge) 
VALUES (1, 1, 1, 675.00);

COMMIT;
