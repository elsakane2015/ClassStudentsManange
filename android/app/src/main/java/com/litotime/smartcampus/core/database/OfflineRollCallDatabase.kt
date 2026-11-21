package com.litotime.smartcampus.core.database

import android.content.Context
import androidx.room.Dao
import androidx.room.Database
import androidx.room.Entity
import androidx.room.Insert
import androidx.room.OnConflictStrategy
import androidx.room.PrimaryKey
import androidx.room.Query
import androidx.room.Room
import androidx.room.RoomDatabase

@Entity(tableName = "roll_call_drafts")
data class RollCallDraftEntity(
    @PrimaryKey val compositeKey: String,
    val rollCallId: Int,
    val studentId: Int,
    val status: String,
    val markedAtIso: String?,
    val markedAtLocal: String?,
    val updatedAt: Long = System.currentTimeMillis()
)

@Dao
interface RollCallDraftDao {
    @Insert(onConflict = OnConflictStrategy.REPLACE)
    suspend fun upsert(item: RollCallDraftEntity)

    @Insert(onConflict = OnConflictStrategy.REPLACE)
    suspend fun upsertAll(items: List<RollCallDraftEntity>)

    @Query("SELECT * FROM roll_call_drafts WHERE rollCallId = :rollCallId")
    suspend fun getByRollCall(rollCallId: Int): List<RollCallDraftEntity>

    @Query("DELETE FROM roll_call_drafts WHERE rollCallId = :rollCallId")
    suspend fun clearByRollCall(rollCallId: Int)
}

@Database(entities = [RollCallDraftEntity::class], version = 1, exportSchema = false)
abstract class OfflineRollCallDatabase : RoomDatabase() {
    abstract fun draftDao(): RollCallDraftDao

    companion object {
        fun create(context: Context): OfflineRollCallDatabase =
            Room.databaseBuilder(
                context.applicationContext,
                OfflineRollCallDatabase::class.java,
                "smartcampus_offline_rollcall.db"
            ).fallbackToDestructiveMigration().build()
    }
}
